"""P3-LTV (roadmap 3.6): PAdES B-T, B-LT and B-LTA, archive re-stamping and long-term validation.

Nothing in this module ANNOUNCES a profile beyond ``PAdES-B-B`` (roadmap T2): every command
reports ``"announced_profile": "PAdES-B-B"`` next to the technical fact (``effective_level``).
The announcement is a platform decision taken only after independent external validation
(docs/fase-3/longo-prazo.md). Nothing here is ICP-Brasil (roadmap T3): the time stamps come
from the OPERATOR TSA (in process, serial allocated by the platform) or from an HTTP TSA whose
kind is declared by the CALLER (``operator`` | ``commercial``) and never inferred from the
response; ``icp_brasil`` is refused because no accredited ACT is contracted.

Commands (registered additively in ``cli.py`` by :func:`add_ltv_commands`):

* ``ltv-sign``      — one PAdES signature built in stages, each an incremental update:
  1. signature (+ signature time stamp -> B-T);
  2. DSS with certificates, CRLs/OCSP responses and a VRI entry (-> B-LT);
  3. document time stamp (-> B-LTA).
  A failing stage never undoes the previous ones and never wastes a later serial: the result
  is the highest level actually reached, and each failure is reported in ``degradations``
  (explicit degradation, viability R5). A TSA that does not answer leaves the file at B-B.
* ``ltv-refresh``   — archive re-stamping: validates the last document time stamp at the
  refresh moment, stores its validation material in the DSS and appends a new document time
  stamp. The file keeps every previous revision (it stays valid) and gains a new layer; its
  SHA-256 changes (hash history: docs/fase-3/longo-prazo.md).
* ``ltv-validate``  — reports the level EFFECTIVELY present per signature (B-B/B-T/B-LT/B-LTA),
  coverage, DSS/VRI presence, revocation coverage from the embedded material, the time
  stamp chain and the date of the last time stamp. ``SignaturePolicyIdentifier`` presence is
  reported, but pyHanko does not check policy conformance (viability §6 item 9): it is always
  ``policy_conformance: "not_checked"``.
* ``ltv-gen-test-pki`` — a throw-away TEST PKI (root, signer, TSA, CRL and OCSP files) so the
  B-LT path can be exercised offline. Never for production.

Revocation material is supplied OFFLINE (``--crl``/``--ocsp`` files, plus what the DSS already
holds). ``--allow-fetching`` exists for production (network to the CAs' CRL/OCSP endpoints),
is off by default and is not exercised by the test-suite (no network).
"""

from __future__ import annotations

import datetime as dt
import hashlib
import logging
import shutil
import tempfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable, Dict, Iterable, List, Optional, Sequence, Tuple

from pdftool.errors import InputRejected, ProcessingError, UsageError

log = logging.getLogger(__name__)

LEVELS: Tuple[str, ...] = ("B-B", "B-T", "B-LT", "B-LTA")
ANNOUNCED_PROFILE = "PAdES-B-B"
ALLOWED_TSA_KINDS = ("operator", "commercial")
REVOCATION_MODES = ("hard-fail", "require")
SIGNATURE_POLICY_IDENTIFIER_OID = "1.2.840.113549.1.9.16.2.15"  # id-aa-ets-sigPolicyId
TEST_MARKER = "TESTE"
TSA_LABELS = {
    "operator": "Carimbo do tempo da operadora — não é carimbo ICP-Brasil",
    "commercial": "Carimbo do tempo de TSA comercial — não é carimbo ICP-Brasil",
}
NOT_ANNOUNCED_NOTE = (
    "Nível técnico interno: o perfil anunciado continua PAdES-B-B até o checklist de "
    "anúncio (validação externa independente) ser cumprido. Não é ICP-Brasil."
)
MAX_FAILURE_TEXT = 300

Clock = Callable[[], dt.datetime]


def _utcnow() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


def _iso(value: Optional[dt.datetime]) -> Optional[str]:
    if value is None:
        return None
    if value.tzinfo is None:
        value = value.replace(tzinfo=dt.timezone.utc)
    return value.astimezone(dt.timezone.utc).isoformat(timespec="seconds")


def _level_index(level: str) -> int:
    return LEVELS.index(level)


def _failure_text(exc: BaseException) -> str:
    text = f"{type(exc).__name__}: {exc}"
    return text[:MAX_FAILURE_TEXT]


# --------------------------------------------------------------------------- TSA plumbing


class TsaFailure(Exception):
    """The TSA did not deliver a token (unreachable, refused, exhausted serials)."""


@dataclass
class TsaSpec:
    """Where the time stamps come from. ``kind`` is declared by the caller (T3)."""

    kind: str = "operator"
    pfx: Optional[Path] = None
    pass_env: Optional[str] = None
    serials: List[int] = field(default_factory=list)
    policy_oid: Optional[str] = None
    accuracy_ms: int = 1000
    url: Optional[str] = None
    token_env: Optional[str] = None
    timeout: int = 10

    def validate(self) -> None:
        if self.kind == "icp_brasil":
            raise UsageError(
                "icp_brasil_not_available",
                "icp_brasil time stamps require a contracted ACT accredited by ITI; none is configured (roadmap T3)",
            )
        if self.kind not in ALLOWED_TSA_KINDS:
            raise UsageError("invalid_tsa_kind", f"--tsa-kind must be one of {', '.join(ALLOWED_TSA_KINDS)}")
        if self.pfx is not None and self.url is not None:
            raise UsageError("tsa_options_conflict", "use either --tsa-pfx (operator TSA in process) or --tsa-url, not both")
        if self.pfx is None and self.url is None:
            raise UsageError("tsa_required", "this level needs a TSA: --tsa-pfx (operator) or --tsa-url")
        if self.pfx is not None:
            if self.kind != "operator":
                raise UsageError("invalid_tsa_kind", "the in-process TSA (--tsa-pfx) is the operator TSA: --tsa-kind must be operator")
            if not self.pass_env or not self.serials or not self.policy_oid:
                raise UsageError("tsa_options_incomplete", "--tsa-pfx requires --tsa-pass-env, --tsa-serial and --tsa-policy-oid")
        if self.timeout < 1 or self.timeout > 120:
            raise UsageError("invalid_tsa_timeout", "--tsa-timeout must be between 1 and 120 seconds")


class _RecordingMixin:
    """Remembers why the TSA failed (no secret: exception class + message only)."""

    failure: Optional[str] = None

    async def async_timestamp(self, message_digest, md_algorithm):  # noqa: D401 - pyHanko hook
        try:
            return await super().async_timestamp(message_digest, md_algorithm)  # type: ignore[misc]
        except Exception as exc:  # noqa: BLE001 - classified by the caller
            self.failure = self.failure or _failure_text(exc)
            raise

    async def async_dummy_response(self, md_algorithm):  # noqa: D401 - pyHanko hook
        try:
            return await super().async_dummy_response(md_algorithm)  # type: ignore[misc]
        except Exception as exc:  # noqa: BLE001
            self.failure = self.failure or _failure_text(exc)
            raise


def _operator_core():
    from pyhanko.sign.timestamps.api import TimeStamper
    from pyhanko.sign.timestamps.common_utils import dummy_digest

    from pdftool.tsa import build_token, check_request, describe_token, granted_response, validate_policy_oid, validate_serial

    class _OperatorCore(TimeStamper):
        """Operator TSA in process; one serial per REAL token, the size-estimation dummy spends none."""

        def __init__(self, creds, serials: Sequence[int], policy_oid: str, accuracy_ms: int, clock: Clock):
            super().__init__(include_nonce=True)
            self.creds = creds
            self._remaining = [validate_serial(s) for s in serials]
            self._placeholder_serial = max(self._remaining) if self._remaining else 1
            self.policy_oid = validate_policy_oid(policy_oid)
            self.accuracy_ms = accuracy_ms
            self.clock = clock
            self.issued: List[Dict[str, Any]] = []

        @property
        def unused_serials(self) -> List[int]:
            return list(self._remaining)

        async def async_dummy_response(self, md_algorithm):
            try:
                return self._dummy_response_cache[md_algorithm]
            except KeyError:
                pass
            _nonce, req = self.request_cms(dummy_digest(md_algorithm), md_algorithm)
            token, _ = build_token(req, self.creds, self._placeholder_serial, self.policy_oid, self.accuracy_ms, now=self.clock(), dry_run=True)
            self._register_dummy(md_algorithm, token)
            return token

        async def async_request_tsa_response(self, req):
            if not self._remaining:
                raise TsaFailure("no allocated TSA serial left for this token")
            check_request(req, self.policy_oid)
            serial = self._remaining.pop(0)
            token, tst_info = build_token(req, self.creds, serial, self.policy_oid, self.accuracy_ms, now=self.clock())
            self.issued.append(describe_token(token, tst_info, self.creds.cert))
            return granted_response(token)

    return _OperatorCore


def _build_timestamper(spec: TsaSpec, clock: Clock):
    """A recording timestamper for ``spec``. Credential problems are errors, not degradation."""
    if spec.pfx is not None:
        from pdftool.tsa import load_tsa_credentials

        creds = load_tsa_credentials(spec.pfx, spec.pass_env or "")
        core = _operator_core()

        class _OperatorLtvTimeStamper(_RecordingMixin, core):
            pass

        return _OperatorLtvTimeStamper(creds, spec.serials, spec.policy_oid or "", spec.accuracy_ms, clock)

    from pyhanko.sign.timestamps import HTTPTimeStamper

    from pdftool.certs import read_passphrase

    headers = {}
    if spec.token_env:
        headers["Authorization"] = "Bearer " + read_passphrase(spec.token_env)

    class _HttpLtvTimeStamper(_RecordingMixin, HTTPTimeStamper):
        issued: List[Dict[str, Any]] = []
        unused_serials: List[int] = []

    stamper = _HttpLtvTimeStamper(spec.url, timeout=spec.timeout, headers=headers or None)
    stamper.issued = []
    del headers
    return stamper


# --------------------------------------------------------------------------- certificates / revinfo


def _load_der_or_pem(paths: Iterable[Path], code: str) -> List[bytes]:
    from asn1crypto import pem

    blobs: List[bytes] = []
    for path in paths:
        if not path.is_file():
            raise UsageError(code, f"file not found: {path.name}")
        data = path.read_bytes()
        if pem.detect(data):
            for _type, _headers, der in pem.unarmor(data, multiple=True):
                blobs.append(der)
        else:
            blobs.append(data)
    return blobs


def load_crls(paths: Iterable[Path]):
    from asn1crypto import crl as asn1_crl

    out = []
    for der in _load_der_or_pem(paths, "crl_file_not_found"):
        try:
            item = asn1_crl.CertificateList.load(der)
            item.native  # force parsing
        except Exception as exc:  # noqa: BLE001
            raise UsageError("invalid_crl_file", f"cannot parse CRL: {type(exc).__name__}") from exc
        out.append(item)
    return out


def load_ocsps(paths: Iterable[Path]):
    from asn1crypto import ocsp as asn1_ocsp

    out = []
    for der in _load_der_or_pem(paths, "ocsp_file_not_found"):
        try:
            item = asn1_ocsp.OCSPResponse.load(der)
            item.native
        except Exception as exc:  # noqa: BLE001
            raise UsageError("invalid_ocsp_file", f"cannot parse OCSP response: {type(exc).__name__}") from exc
        out.append(item)
    return out


def _validation_context(
    trust_roots,
    *,
    crls=(),
    ocsps=(),
    other_certs=(),
    moment: Optional[dt.datetime] = None,
    allow_fetching: bool = False,
    revocation_mode: str = "hard-fail",
):
    from pyhanko_certvalidator import ValidationContext

    kwargs: Dict[str, Any] = {
        "trust_roots": list(trust_roots),
        "other_certs": list(other_certs),
        "allow_fetching": allow_fetching,
        "revocation_mode": revocation_mode,
        "crls": list(crls),
        "ocsps": list(ocsps),
    }
    if moment is not None:
        kwargs["moment"] = moment
    return ValidationContext(**kwargs)


def _cert_key(cert) -> bytes:
    return cert.dump()


def _is_self_signed(cert) -> bool:
    return cert.subject == cert.issuer


def _find_issuer(cert, pool: Sequence[Any]):
    for candidate in pool:
        if candidate.subject == cert.issuer and _cert_key(candidate) != _cert_key(cert):
            return candidate
    return None


def _ocsp_status_for(cert, ocsps) -> Optional[str]:
    for resp in ocsps:
        try:
            if resp["response_status"].native != "successful":
                continue
            basic = resp["response_bytes"]["response"].parsed
            for single in basic["tbs_response_data"]["responses"]:
                if single["cert_id"]["serial_number"].native == cert.serial_number:
                    return str(single["cert_status"].name)
        except Exception:  # noqa: BLE001 - malformed entries simply do not count
            continue
    return None


def _crl_status_for(cert, crls) -> Optional[str]:
    for item in crls:
        try:
            tbs = item["tbs_cert_list"]
            if tbs["issuer"] != cert.issuer:
                continue
            revoked = tbs["revoked_certificates"]
            for entry in (revoked or []):
                if entry["user_certificate"].native == cert.serial_number:
                    return "revoked"
            return "good"
        except Exception:  # noqa: BLE001
            continue
    return None


def revocation_coverage(leaf, pool: Sequence[Any], anchors: Sequence[Any], crls, ocsps) -> Dict[str, Any]:
    """Is there embedded revocation material for every non-anchor certificate of the chain?"""
    anchor_keys = {_cert_key(a) for a in anchors}
    missing: List[str] = []
    revoked: List[str] = []
    checked = 0
    cert = leaf
    seen = set()
    chain_complete = True
    while cert is not None and _cert_key(cert) not in seen:
        seen.add(_cert_key(cert))
        if _cert_key(cert) in anchor_keys or _is_self_signed(cert):
            break
        checked += 1
        status = _ocsp_status_for(cert, ocsps) or _crl_status_for(cert, crls)
        subject = _subject(cert)
        if status is None:
            missing.append(subject)
        elif status == "revoked":
            revoked.append(subject)
        issuer = _find_issuer(cert, pool)
        if issuer is None:
            chain_complete = False
            break
        cert = issuer
    return {
        "covered": not missing and not revoked and chain_complete,
        "certificates_checked": checked,
        "missing": missing,
        "revoked": revoked,
        "chain_complete": chain_complete,
    }


# --------------------------------------------------------------------------- DSS reading


def _dss_contents(reader) -> Dict[str, Any]:
    from asn1crypto import crl as asn1_crl
    from asn1crypto import ocsp as asn1_ocsp
    from asn1crypto import x509 as asn1_x509

    info: Dict[str, Any] = {"present": False, "certs": [], "crls": [], "ocsps": [], "vri_keys": set()}
    try:
        dss = reader.root["/DSS"]
    except Exception:  # noqa: BLE001 - no DSS
        return info
    info["present"] = True

    def streams(key):
        try:
            return [ref.get_object().data for ref in dss.get(key, [])]
        except Exception:  # noqa: BLE001
            return []

    for data in streams("/Certs"):
        try:
            info["certs"].append(asn1_x509.Certificate.load(data))
        except Exception:  # noqa: BLE001
            pass
    for data in streams("/CRLs"):
        try:
            info["crls"].append(asn1_crl.CertificateList.load(data))
        except Exception:  # noqa: BLE001
            pass
    for data in streams("/OCSPs"):
        try:
            info["ocsps"].append(asn1_ocsp.OCSPResponse.load(data))
        except Exception:  # noqa: BLE001
            pass
    try:
        info["vri_keys"] = {str(k).lstrip("/").upper() for k in dss.get("/VRI", {}).keys()}
    except Exception:  # noqa: BLE001
        info["vri_keys"] = set()
    return info


def _vri_key(sig) -> str:
    # Same identifier pyHanko writes (DocumentSecurityStore.sig_content_identifier over the
    # hex rendering of the CMS bytes, as in async_add_validation_info). SHA-1 here is a
    # lookup key defined by PAdES, not a security primitive.
    return hashlib.sha1(sig.pkcs7_content.hex().encode("ascii")).hexdigest().upper()


def _subject(cert) -> Optional[str]:
    from pdftool.certs import cert_from_asn1, cert_summary

    try:
        return cert_summary(cert_from_asn1(cert))["subject"]
    except Exception:  # noqa: BLE001 - fall back to asn1crypto's rendering
        return cert.subject.human_friendly


def _signature_time_stamp_token(sig):
    try:
        signer_info = sig.signer_info
        attrs = signer_info["unsigned_attrs"]
        if attrs.native is None:
            return None
        for attr in attrs:
            if attr["type"].native == "signature_time_stamp_token":
                return attr["values"][0]
    except Exception:  # noqa: BLE001
        return None
    return None


def _tst_signer_cert(token):
    try:
        signed_data = token["content"]
        certs = [c.chosen for c in signed_data["certificates"]]
        sid = signed_data["signer_infos"][0]["sid"]
        if sid.name == "issuer_and_serial_number":
            want = sid.chosen
            for cert in certs:
                if cert.issuer == want["issuer"] and cert.serial_number == want["serial_number"].native:
                    return cert, certs
        return (certs[0] if certs else None), certs
    except Exception:  # noqa: BLE001
        return None, []


def _tst_gen_time(token) -> Optional[dt.datetime]:
    try:
        return token["content"]["encap_content_info"]["content"].parsed["gen_time"].native
    except Exception:  # noqa: BLE001
        return None


def _has_policy_identifier(sig) -> bool:
    try:
        for attr in sig.signer_info["signed_attrs"]:
            if attr["type"].dotted == SIGNATURE_POLICY_IDENTIFIER_OID:
                return True
    except Exception:  # noqa: BLE001
        return False
    return False


# --------------------------------------------------------------------------- analysis (ltv-validate)


def _open_reader(path: Path):
    from pyhanko.pdf_utils.reader import PdfFileReader

    if not path.is_file():
        raise InputRejected("missing_input", f"input file not found: {path.name}")
    handle = path.open("rb")
    try:
        reader = PdfFileReader(handle, strict=False)
        if reader.encrypted:
            raise InputRejected("encrypted_pdf", "encrypted PDFs are not supported")
    except InputRejected:
        handle.close()
        raise
    except Exception as exc:  # noqa: BLE001
        handle.close()
        raise InputRejected("invalid_pdf", f"cannot parse PDF: {type(exc).__name__}") from exc
    return handle, reader


def analyse(
    path: Path,
    trust_roots,
    tsa_trust_roots=None,
    crls=(),
    ocsps=(),
    at: Optional[dt.datetime] = None,
) -> Dict[str, Any]:
    """What is EFFECTIVELY in the file. Offline: embedded DSS + material given by the caller."""
    from pyhanko.sign.validation import validate_pdf_signature, validate_pdf_timestamp

    from pdftool.certs import cert_from_asn1, cert_summary
    from pdftool.validate import KEY_USAGE

    tsa_trust_roots = list(tsa_trust_roots) if tsa_trust_roots else list(trust_roots)
    at = at or _utcnow()
    handle, reader = _open_reader(path)
    try:
        dss = _dss_contents(reader)
        regular = list(reader.embedded_regular_signatures)
        doc_ts = list(reader.embedded_timestamp_signatures)
        all_crls = list(dss["crls"]) + list(crls)
        all_ocsps = list(dss["ocsps"]) + list(ocsps)
        base_pool = list(dss["certs"]) + list(trust_roots) + list(tsa_trust_roots)

        # --- document time stamps: each one is checked at the time of the NEXT one (its proof of
        # existence), the last one at ``at``. That is the LTA reasoning: an expired TSA certificate
        # is fine as long as a later, still valid, time stamp covers it.
        doc_entries: List[Dict[str, Any]] = []
        gen_times: List[Optional[dt.datetime]] = []
        for ts in doc_ts:
            token = ts.signed_data
            gen_times.append(_tst_gen_time({"content": token}) if token is not None else None)
        for index, ts in enumerate(doc_ts):
            moment = at
            if index + 1 < len(doc_ts) and gen_times[index + 1] is not None:
                moment = gen_times[index + 1]
            entry: Dict[str, Any] = {
                "field_name": str(ts.field_name),
                "gen_time": _iso(gen_times[index]),
                "validated_at": _iso(moment),
                "intact": False,
                "valid": False,
                "trusted": False,
                "coverage": None,
                "tsa_subject": None,
                "tsa_cert_not_after": None,
                "tsa_certificate_test": None,
                "errors": [],
            }
            try:
                cert = ts.signer_cert
                summary = cert_summary(cert_from_asn1(cert))
                entry["tsa_subject"] = summary["subject"]
                entry["tsa_cert_not_after"] = summary["not_after"]
                entry["tsa_certificate_test"] = TEST_MARKER in summary["subject"].upper()
            except Exception as exc:  # noqa: BLE001
                entry["errors"].append(f"tsa_certificate_unavailable: {type(exc).__name__}")
            try:
                vc = _validation_context(
                    tsa_trust_roots, crls=all_crls, ocsps=all_ocsps, other_certs=base_pool, moment=moment, revocation_mode="hard-fail"
                )
                status = validate_pdf_timestamp(ts, validation_context=vc)
                entry["intact"] = bool(status.intact)
                entry["valid"] = bool(status.valid)
                entry["trusted"] = bool(status.trusted)
                entry["coverage"] = status.coverage.name if status.coverage is not None else None
                if not status.trusted:
                    entry["errors"].append(f"trust: {getattr(status.trust_problem_indic, 'name', status.trust_problem_indic)}")
            except Exception as exc:  # noqa: BLE001
                entry["errors"].append(f"validation_error: {_failure_text(exc)}")
            doc_entries.append(entry)

        chain_valid = bool(doc_entries) and all(e["intact"] and e["valid"] and e["trusted"] for e in doc_entries)

        sig_entries: List[Dict[str, Any]] = []
        for sig in regular:
            entry = {
                "field_name": str(sig.field_name),
                "intact": False,
                "valid": False,
                "trusted": False,
                "level": None,
                "coverage": None,
                "modification_level": None,
                "signer_subject": None,
                "signature_timestamp": {"present": False, "gen_time": None, "intact": False, "valid": False, "trusted": False, "tsa_subject": None},
                "vri_present": False,
                "revocation": {"signer": None, "signature_timestamp": None, "embedded_only": True},
                "signature_policy_identifier_present": _has_policy_identifier(sig),
                "policy_conformance": "not_checked",
                "errors": [],
            }
            token = _signature_time_stamp_token(sig)
            ts_gen = _tst_gen_time(token) if token is not None else None
            try:
                signer_cert = sig.signer_cert
                entry["signer_subject"] = _subject(signer_cert)
            except Exception:  # noqa: BLE001
                signer_cert = None
            cms_certs = []
            try:
                cms_certs = [c.chosen for c in sig.signed_data["certificates"]]
            except Exception:  # noqa: BLE001
                cms_certs = []
            tst_cert, tst_certs = _tst_signer_cert(token) if token is not None else (None, [])
            pool = base_pool + cms_certs + tst_certs
            moment = ts_gen or at
            try:
                signer_vc = _validation_context(trust_roots, crls=all_crls, ocsps=all_ocsps, other_certs=pool, moment=moment, revocation_mode="hard-fail")
                ts_vc = _validation_context(tsa_trust_roots, crls=all_crls, ocsps=all_ocsps, other_certs=pool, moment=moment, revocation_mode="hard-fail")
                status = validate_pdf_signature(sig, signer_validation_context=signer_vc, ts_validation_context=ts_vc, key_usage_settings=KEY_USAGE)
                entry["intact"] = bool(status.intact)
                entry["valid"] = bool(status.valid)
                entry["trusted"] = bool(status.trusted)
                entry["coverage"] = status.coverage.name if status.coverage is not None else None
                level = status.modification_level
                entry["modification_level"] = level.name if level is not None else None
                tsv = status.timestamp_validity
                if tsv is not None:
                    entry["signature_timestamp"].update(
                        {"present": True, "gen_time": _iso(tsv.timestamp), "intact": bool(tsv.intact), "valid": bool(tsv.valid), "trusted": bool(tsv.trusted)}
                    )
                if not status.trusted:
                    entry["errors"].append(f"trust: {getattr(status.trust_problem_indic, 'name', status.trust_problem_indic)}")
            except Exception as exc:  # noqa: BLE001
                entry["errors"].append(f"validation_error: {_failure_text(exc)}")
            if token is not None and not entry["signature_timestamp"]["present"]:
                entry["signature_timestamp"].update({"present": True, "gen_time": _iso(ts_gen)})
            if tst_cert is not None:
                entry["signature_timestamp"]["tsa_subject"] = _subject(tst_cert)

            entry["vri_present"] = dss["present"] and _vri_key(sig) in dss["vri_keys"]
            if signer_cert is not None:
                entry["revocation"]["signer"] = revocation_coverage(signer_cert, pool, trust_roots, dss["crls"], dss["ocsps"])
            if tst_cert is not None:
                entry["revocation"]["signature_timestamp"] = revocation_coverage(tst_cert, pool, tsa_trust_roots, dss["crls"], dss["ocsps"])

            crypto_ok = entry["intact"] and entry["valid"]
            sts = entry["signature_timestamp"]
            ts_ok = sts["present"] and sts["intact"] and sts["valid"]
            rev = entry["revocation"]
            lt_ok = (
                dss["present"]
                and rev["signer"] is not None
                and rev["signer"]["covered"]
                and rev["signature_timestamp"] is not None
                and rev["signature_timestamp"]["covered"]
            )
            later_ok = entry["modification_level"] in ("NONE", "LTA_UPDATES") or entry["coverage"] == "ENTIRE_FILE"
            lta_ok = chain_valid and bool(doc_entries)
            if not crypto_ok:
                entry["level"] = None
            elif not ts_ok:
                entry["level"] = "B-B"
            elif not lt_ok:
                entry["level"] = "B-T"
            elif not lta_ok:
                entry["level"] = "B-LT"
            else:
                entry["level"] = "B-LTA"
            entry["later_changes_lta_only"] = bool(later_ok)
            sig_entries.append(entry)
    finally:
        handle.close()

    levels = [e["level"] for e in sig_entries]
    if not sig_entries or any(level is None for level in levels):
        effective = None
    else:
        effective = min(levels, key=_level_index)

    timestamp_times = [e["gen_time"] for e in doc_entries if e["gen_time"]]
    timestamp_times += [e["signature_timestamp"]["gen_time"] for e in sig_entries if e["signature_timestamp"]["gen_time"]]
    last_ts = max(timestamp_times) if timestamp_times else None
    archive = None
    if doc_entries:
        last = doc_entries[-1]
        archive = {
            "field_name": last["field_name"],
            "gen_time": last["gen_time"],
            "tsa_subject": last["tsa_subject"],
            "tsa_cert_not_after": last["tsa_cert_not_after"],
            "tsa_certificate_test": last["tsa_certificate_test"],
        }
    return {
        "ok": True,
        "announced_profile": ANNOUNCED_PROFILE,
        "announced": False,
        "effective_level": effective,
        "icp_brasil": False,
        "note": NOT_ANNOUNCED_NOTE,
        "validated_at": _iso(at),
        "signature_count": len(sig_entries),
        "document_timestamp_count": len(doc_entries),
        "all_intact": bool(sig_entries) and all(e["intact"] for e in sig_entries),
        "all_valid": bool(sig_entries) and all(e["intact"] and e["valid"] for e in sig_entries),
        "all_later_changes_lta_only": bool(sig_entries) and all(e["later_changes_lta_only"] for e in sig_entries),
        "timestamp_chain_valid": chain_valid if doc_entries else None,
        "dss": {
            "present": dss["present"],
            "vri_entries": len(dss["vri_keys"]),
            "certs": len(dss["certs"]),
            "crls": len(dss["crls"]),
            "ocsps": len(dss["ocsps"]),
        },
        "last_timestamp_at": last_ts,
        "archive_timestamp": archive,
        "signatures": sig_entries,
        "document_timestamps": doc_entries,
        "revocation": "embedded_dss",
    }


# --------------------------------------------------------------------------- staged signing


def _load_signer(pfx: Path, pass_env: str):
    from pyhanko.sign import signers

    from pdftool.certs import read_passphrase

    passphrase = read_passphrase(pass_env)
    if not pfx.is_file():
        raise InputRejected("pfx_not_found", f"PKCS#12 file not found: {pfx.name}")
    try:
        signer = signers.SimpleSigner.load_pkcs12(str(pfx), passphrase=passphrase.encode("utf-8"))
    finally:
        del passphrase
    if signer is None:
        raise InputRejected("pfx_load_failed", "could not load PKCS#12: wrong passphrase or unsupported file")
    return signer


def _stage_signature(in_path: Path, out_path: Path, signer, field_name: Optional[str], reason, location, timestamper) -> str:
    from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
    from pyhanko.sign import fields, signers

    from pdftool.sign import _choose_field_name

    with in_path.open("rb") as inf:
        try:
            writer = IncrementalPdfFileWriter(inf, strict=False)
        except Exception as exc:  # noqa: BLE001
            raise InputRejected("invalid_pdf", f"pyHanko cannot parse PDF: {type(exc).__name__}") from exc
        if writer.prev.encrypted:
            raise InputRejected("encrypted_pdf", "signing encrypted PDFs is not supported")
        name = _choose_field_name(writer.prev, field_name)
        meta = signers.PdfSignatureMetadata(
            field_name=name,
            md_algorithm="sha256",
            subfilter=fields.SigSeedSubFilter.PADES,
            use_pades_lta=False,
            embed_validation_info=False,
            reason=reason or None,
            location=location or None,
        )
        pdf_signer = signers.PdfSigner(meta, signer, timestamper=timestamper)
        with out_path.open("wb") as outf:
            pdf_signer.sign_pdf(writer, output=outf)
    return name


def _stage_dss(in_path: Path, out_path: Path, vc, field_names: Optional[Sequence[str]] = None) -> None:
    """One incremental update per signature: its chain, CRLs/OCSP and a VRI entry in the DSS."""
    from pyhanko.pdf_utils.reader import PdfFileReader
    from pyhanko.sign.validation import add_validation_info

    current = in_path
    tmp_dir = Path(tempfile.mkdtemp(prefix="ltv-dss-", dir=str(out_path.parent)))
    try:
        with in_path.open("rb") as probe:
            names = [str(s.field_name) for s in PdfFileReader(probe, strict=False).embedded_regular_signatures]
        if field_names is not None:
            names = [n for n in names if n in set(field_names)]
        for index, name in enumerate(names):
            target = tmp_dir / f"dss-{index}.pdf"
            with current.open("rb") as inf:
                reader = PdfFileReader(inf, strict=False)
                sig = next(s for s in reader.embedded_regular_signatures if str(s.field_name) == name)
                with target.open("wb") as outf:
                    add_validation_info(sig, vc, skip_timestamp=False, add_vri_entry=True, output=outf)
            current = target
        shutil.copyfile(current, out_path)
    finally:
        shutil.rmtree(tmp_dir, ignore_errors=True)


def _stage_document_timestamp(in_path: Path, out_path: Path, timestamper, vc) -> None:
    from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
    from pyhanko.sign import signers

    with in_path.open("rb") as inf:
        writer = IncrementalPdfFileWriter(inf, strict=False)
        with out_path.open("wb") as outf:
            signers.PdfTimeStamper(timestamper).timestamp_pdf(writer, "sha256", validation_context=vc, output=outf)


def _timestamp_facts(timestamper, spec: TsaSpec, purposes: Sequence[str]) -> List[Dict[str, Any]]:
    out = []
    for index, issued in enumerate(getattr(timestamper, "issued", []) or []):
        out.append(
            {
                **issued,
                "purpose": purposes[index] if index < len(purposes) else "document",
                "tsa_kind": spec.kind,
                "label": TSA_LABELS[spec.kind],
                "announced": False,
            }
        )
    return out


def _gather_revinfo(trust_paths, crl_paths, ocsp_paths):
    from pdftool.validate import load_trust_roots

    roots = load_trust_roots(list(trust_paths)) if trust_paths else []
    return roots, load_crls(crl_paths or []), load_ocsps(ocsp_paths or [])


def ltv_sign(
    in_path: Path,
    out_path: Path,
    pfx: Path,
    pass_env: str,
    level: str,
    tsa: TsaSpec,
    *,
    field_name: Optional[str] = None,
    reason: Optional[str] = None,
    location: Optional[str] = None,
    trust_paths: Sequence[Path] = (),
    crl_paths: Sequence[Path] = (),
    ocsp_paths: Sequence[Path] = (),
    allow_fetching: bool = False,
    revocation_mode: str = "hard-fail",
    clock: Optional[Clock] = None,
) -> Dict[str, Any]:
    from pdftool.certs import cert_from_asn1, cert_summary
    from pdftool.compose import reject_same_path

    clock = clock or _utcnow
    if level not in LEVELS[1:]:
        raise UsageError("invalid_level", "--level must be B-T, B-LT or B-LTA (plain B-B is the `sign` command)")
    if revocation_mode not in REVOCATION_MODES:
        raise UsageError("invalid_revocation_mode", "--revocation-mode must be hard-fail or require (never soft-fail: R5)")
    tsa.validate()
    if _level_index(level) >= _level_index("B-LT") and not trust_paths:
        raise UsageError("trust_required", "B-LT and B-LTA need --trust (the chains are validated before embedding)")
    reject_same_path(in_path, out_path)
    roots, crls, ocsps = _gather_revinfo(trust_paths, crl_paths, ocsp_paths)
    signer = _load_signer(pfx, pass_env)
    timestamper = _build_timestamper(tsa, clock)

    out_path.parent.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix="ltv-sign-", dir=str(out_path.parent)))
    degradations: List[Dict[str, str]] = []
    purposes: List[str] = []
    reached = "B-B"
    try:
        stage1 = work / "1-signature.pdf"
        try:
            name = _stage_signature(in_path, stage1, signer, field_name, reason, location, timestamper)
            purposes.append("signature")
            reached = "B-T"
        except InputRejected:
            raise
        except Exception as exc:  # noqa: BLE001 - TSA problems degrade; anything else is an error
            failure = getattr(timestamper, "failure", None)
            if failure is None:
                raise ProcessingError("signing_failed", f"signing failed: {_failure_text(exc)}") from exc
            degradations.append({"step": "signature_timestamp", "code": "tsa_unavailable", "message": failure})
            stage1.unlink(missing_ok=True)
            name = _stage_signature(in_path, stage1, signer, field_name, reason, location, None)
        current = stage1

        if reached == "B-T" and _level_index(level) >= _level_index("B-LT"):
            stage2 = work / "2-dss.pdf"
            vc = _validation_context(roots, crls=crls, ocsps=ocsps, moment=clock(), allow_fetching=allow_fetching, revocation_mode=revocation_mode)
            try:
                _stage_dss(current, stage2, vc, field_names=[name])
                report = analyse(stage2, roots, crls=crls, ocsps=ocsps, at=clock())
                mine = next((s for s in report["signatures"] if s["field_name"] == name), None)
                if mine is None or mine["level"] not in ("B-LT", "B-LTA"):
                    rev = (mine or {}).get("revocation", {})
                    missing = [*(rev.get("signer") or {}).get("missing", []), *(rev.get("signature_timestamp") or {}).get("missing", [])]
                    raise ProcessingError("revocation_incomplete", "revocation material missing for: " + ("; ".join(missing) or "the chain"))
                current = stage2
                reached = "B-LT"
            except Exception as exc:  # noqa: BLE001 - explicit degradation to B-T
                code = exc.code if isinstance(exc, ProcessingError) else "validation_info_unavailable"
                degradations.append({"step": "validation_info", "code": code, "message": _failure_text(exc)})

        if reached == "B-LT" and level == "B-LTA":
            stage3 = work / "3-doc-timestamp.pdf"
            vc = _validation_context(roots, crls=crls, ocsps=ocsps, moment=clock(), allow_fetching=allow_fetching, revocation_mode=revocation_mode)
            timestamper.failure = None
            try:
                _stage_document_timestamp(current, stage3, timestamper, vc)
                purposes.append("document")
                current = stage3
                reached = "B-LTA"
            except Exception as exc:  # noqa: BLE001 - explicit degradation to B-LT
                failure = getattr(timestamper, "failure", None)
                degradations.append(
                    {"step": "document_timestamp", "code": "tsa_unavailable" if failure else "document_timestamp_failed", "message": failure or _failure_text(exc)}
                )
        shutil.copyfile(current, out_path)
    finally:
        shutil.rmtree(work, ignore_errors=True)

    final_report = analyse(out_path, roots, crls=crls, ocsps=ocsps, at=clock())
    summary = cert_summary(cert_from_asn1(signer.signing_cert))
    issued = _timestamp_facts(timestamper, tsa, purposes)
    return {
        "ok": True,
        "announced_profile": ANNOUNCED_PROFILE,
        "profile": ANNOUNCED_PROFILE,
        "announced": False,
        "requested_level": level,
        "effective_level": reached,
        "validated_level": final_report["effective_level"],
        "degraded": bool(degradations),
        "degradations": degradations,
        "icp_brasil": False,
        "note": NOT_ANNOUNCED_NOTE,
        "field_name": name,
        "signer_subject": summary["subject"],
        "issuer": summary["issuer"],
        "cert_fingerprint_sha256": summary["cert_fingerprint_sha256"],
        "not_after": summary["not_after"],
        "tsa_kind": tsa.kind,
        "timestamps": issued,
        "serials_used": [t["serial"] for t in issued if "serial" in t],
        "serials_unused": [str(s) for s in getattr(timestamper, "unused_serials", [])],
        "dss": final_report["dss"],
        "last_timestamp_at": final_report["last_timestamp_at"],
        "archive_timestamp": final_report["archive_timestamp"],
        "revocation_embedded": final_report["dss"]["present"] and reached in ("B-LT", "B-LTA"),
        "signature_policy_identifier_present": any(s["signature_policy_identifier_present"] for s in final_report["signatures"]),
        "sha256": hashlib.sha256(out_path.read_bytes()).hexdigest(),
    }


def ltv_refresh(
    in_path: Path,
    out_path: Path,
    tsa: TsaSpec,
    *,
    trust_paths: Sequence[Path] = (),
    crl_paths: Sequence[Path] = (),
    ocsp_paths: Sequence[Path] = (),
    allow_fetching: bool = False,
    revocation_mode: str = "hard-fail",
    clock: Optional[Clock] = None,
) -> Dict[str, Any]:
    """Archive re-stamping: DSS for what is not yet covered + a new document time stamp.

    A TSA failure here is an ERROR (``tsa_unavailable``, exit 3) and nothing is written: the
    existing file is still valid, and the platform retries before the TSA certificate expires.
    """
    from pyhanko.pdf_utils.reader import PdfFileReader
    from pyhanko.sign import signers

    from pdftool.compose import reject_same_path

    clock = clock or _utcnow
    if revocation_mode not in REVOCATION_MODES:
        raise UsageError("invalid_revocation_mode", "--revocation-mode must be hard-fail or require")
    tsa.validate()
    if not trust_paths:
        raise UsageError("trust_required", "ltv-refresh needs --trust (the last time stamp is validated first)")
    reject_same_path(in_path, out_path)
    roots, crls, ocsps = _gather_revinfo(trust_paths, crl_paths, ocsp_paths)
    before = analyse(in_path, roots, crls=crls, ocsps=ocsps, at=clock())
    if before["signature_count"] == 0:
        raise InputRejected("no_signatures", "the PDF has no signature to preserve")
    if not before["all_valid"]:
        raise InputRejected("invalid_signatures", "refusing to re-stamp a file whose signatures are not intact and valid")
    last = before["archive_timestamp"]
    if last and last["tsa_cert_not_after"]:
        expires = dt.datetime.fromisoformat(last["tsa_cert_not_after"])
        if expires <= clock():
            raise ProcessingError("archive_timestamp_expired", "the certificate of the last archive time stamp already expired; re-stamping is no longer possible")

    timestamper = _build_timestamper(tsa, clock)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix="ltv-refresh-", dir=str(out_path.parent)))
    try:
        current = in_path
        uncovered = [s["field_name"] for s in before["signatures"] if s["level"] in ("B-B", "B-T")]
        with_ts = [s["field_name"] for s in before["signatures"] if s["signature_timestamp"]["present"]]
        targets = [n for n in uncovered if n in with_ts]
        if targets:
            stage = work / "dss.pdf"
            vc = _validation_context(roots, crls=crls, ocsps=ocsps, moment=clock(), allow_fetching=allow_fetching, revocation_mode=revocation_mode)
            try:
                _stage_dss(current, stage, vc, field_names=targets)
                current = stage
            except Exception as exc:  # noqa: BLE001
                raise ProcessingError("validation_info_unavailable", f"could not embed validation info: {_failure_text(exc)}") from exc

        stage = work / "restamp.pdf"
        vc = _validation_context(
            roots,
            crls=crls,
            ocsps=ocsps,
            other_certs=_dss_certs_of(current),
            moment=clock(),
            allow_fetching=allow_fetching,
            revocation_mode=revocation_mode,
        )
        try:
            with current.open("rb") as inf:
                reader = PdfFileReader(inf, strict=False)
                with stage.open("wb") as outf:
                    signers.PdfTimeStamper(timestamper).update_archival_timestamp_chain(reader, vc, in_place=False, output=outf)
        except Exception as exc:  # noqa: BLE001
            failure = getattr(timestamper, "failure", None)
            if failure:
                raise ProcessingError("tsa_unavailable", f"the TSA did not deliver the archive time stamp: {failure}") from exc
            raise ProcessingError("restamp_failed", f"re-stamping failed: {_failure_text(exc)}") from exc
        shutil.copyfile(stage, out_path)
    finally:
        shutil.rmtree(work, ignore_errors=True)

    after = analyse(out_path, roots, crls=crls, ocsps=ocsps, at=clock())
    issued = _timestamp_facts(timestamper, tsa, ["archive"] * 4)
    return {
        "ok": True,
        "announced_profile": ANNOUNCED_PROFILE,
        "announced": False,
        "icp_brasil": False,
        "note": NOT_ANNOUNCED_NOTE,
        "level_before": before["effective_level"],
        "effective_level": after["effective_level"],
        "document_timestamps_before": before["document_timestamp_count"],
        "document_timestamps_after": after["document_timestamp_count"],
        "tsa_kind": tsa.kind,
        "timestamps": issued,
        "serials_used": [t["serial"] for t in issued if "serial" in t],
        "serials_unused": [str(s) for s in getattr(timestamper, "unused_serials", [])],
        "dss": after["dss"],
        "timestamp_chain_valid": after["timestamp_chain_valid"],
        "last_timestamp_at": after["last_timestamp_at"],
        "archive_timestamp": after["archive_timestamp"],
        "sha256_before": hashlib.sha256(in_path.read_bytes()).hexdigest(),
        "sha256": hashlib.sha256(out_path.read_bytes()).hexdigest(),
    }


def _dss_certs_of(path: Path):
    handle, reader = _open_reader(path)
    try:
        return _dss_contents(reader)["certs"]
    finally:
        handle.close()


def ltv_validate(
    in_path: Path,
    trust_paths: Sequence[Path] = (),
    tsa_trust_paths: Sequence[Path] = (),
    crl_paths: Sequence[Path] = (),
    ocsp_paths: Sequence[Path] = (),
    at: Optional[str] = None,
) -> Dict[str, Any]:
    from pdftool.validate import load_trust_roots

    if not trust_paths:
        raise UsageError("trust_required", "ltv-validate needs --trust: without an anchor no level beyond integrity can be stated")
    moment = None
    if at:
        try:
            moment = dt.datetime.fromisoformat(at.replace("Z", "+00:00"))
        except ValueError:
            raise UsageError("invalid_at", "--at must be an ISO-8601 date-time") from None
        if moment.tzinfo is None:
            moment = moment.replace(tzinfo=dt.timezone.utc)
    roots = load_trust_roots(list(trust_paths))
    tsa_roots = load_trust_roots(list(tsa_trust_paths)) if tsa_trust_paths else None
    return analyse(in_path, roots, tsa_roots, crls=load_crls(crl_paths), ocsps=load_ocsps(ocsp_paths), at=moment)


# --------------------------------------------------------------------------- TEST PKI


class TestPki:
    """A throw-away TEST PKI (EC P-256): root, signer, TSA; CRLs and OCSP responses on demand.

    Every CN carries "TESTE". The certificates declare a CRL distribution point and an OCSP URL
    under ``.invalid`` (RFC 2606: never resolvable) so that revocation checking is really
    exercised with the material handed over offline.
    """

    __test__ = False  # not a pytest class

    CRL_URL = "http://ac-teste-ltv.invalid/raiz.crl"
    OCSP_URL = "http://ocsp.ac-teste-ltv.invalid/"

    def __init__(self, now: Optional[dt.datetime] = None, days: int = 30):
        from cryptography import x509
        from cryptography.hazmat.primitives import hashes
        from cryptography.hazmat.primitives.asymmetric import ec
        from cryptography.x509.oid import NameOID

        self._x509, self._hashes, self._ec, self._NameOID = x509, hashes, ec, NameOID
        self.now = (now or _utcnow()).replace(microsecond=0)
        self.root_key = ec.generate_private_key(ec.SECP256R1())
        name = self._name("AssinaVelox AC Raiz LTV TESTE")
        ski = x509.SubjectKeyIdentifier.from_public_key(self.root_key.public_key())
        self.root = (
            x509.CertificateBuilder()
            .subject_name(name)
            .issuer_name(name)
            .public_key(self.root_key.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(self.now - dt.timedelta(days=1))
            .not_valid_after(self.now + dt.timedelta(days=max(days, 1) * 20))
            .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
            .add_extension(x509.KeyUsage(False, False, False, False, False, True, True, False, False), critical=True)
            .add_extension(ski, critical=False)
            .sign(self.root_key, hashes.SHA256())
        )
        self._root_ski = ski

    def _name(self, cn: str):
        x509, NameOID = self._x509, self._NameOID
        if TEST_MARKER not in cn.upper():
            cn = f"{cn} {TEST_MARKER}"
        return x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, cn), x509.NameAttribute(NameOID.ORGANIZATION_NAME, "AssinaVelox"), x509.NameAttribute(NameOID.COUNTRY_NAME, "BR")])

    def _leaf(self, cn: str, not_before: dt.datetime, not_after: dt.datetime, tsa: bool):
        x509, hashes, ec = self._x509, self._hashes, self._ec
        from cryptography.x509.oid import AuthorityInformationAccessOID, ExtendedKeyUsageOID

        key = ec.generate_private_key(ec.SECP256R1())
        builder = (
            x509.CertificateBuilder()
            .subject_name(self._name(cn))
            .issuer_name(self.root.subject)
            .public_key(key.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(not_before)
            .not_valid_after(not_after)
            .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
            .add_extension(x509.KeyUsage(True, True, False, False, False, False, False, False, False), critical=True)
            .add_extension(x509.SubjectKeyIdentifier.from_public_key(key.public_key()), critical=False)
            .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(self._root_ski), critical=False)
            .add_extension(
                x509.CRLDistributionPoints([x509.DistributionPoint([x509.UniformResourceIdentifier(self.CRL_URL)], None, None, None)]),
                critical=False,
            )
            .add_extension(
                x509.AuthorityInformationAccess([x509.AccessDescription(AuthorityInformationAccessOID.OCSP, x509.UniformResourceIdentifier(self.OCSP_URL))]),
                critical=False,
            )
        )
        if tsa:
            builder = builder.add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.TIME_STAMPING]), critical=True)
        return key, builder.sign(self.root_key, hashes.SHA256())

    def signer(self, days: int = 30, not_before: Optional[dt.datetime] = None, cn: str = "AssinaVelox Operadora LTV TESTE"):
        start = not_before or (self.now - dt.timedelta(minutes=10))
        return self._leaf(cn, start, start + dt.timedelta(days=days), tsa=False)

    def tsa(self, days: int = 30, not_before: Optional[dt.datetime] = None, cn: str = "AssinaVelox TSA LTV TESTE"):
        start = not_before or (self.now - dt.timedelta(minutes=10))
        return self._leaf(cn, start, start + dt.timedelta(days=days), tsa=True)

    def crl(self, this_update: Optional[dt.datetime] = None, next_update_days: int = 30, revoked: Sequence[Any] = ()) -> bytes:
        x509, hashes = self._x509, self._hashes
        from cryptography.hazmat.primitives import serialization

        this_update = (this_update or (self.now - dt.timedelta(minutes=5))).replace(microsecond=0)
        builder = (
            x509.CertificateRevocationListBuilder()
            .issuer_name(self.root.subject)
            .last_update(this_update)
            .next_update(this_update + dt.timedelta(days=next_update_days))
            .add_extension(x509.CRLNumber(int(this_update.timestamp())), critical=False)
            .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(self._root_ski), critical=False)
        )
        for cert in revoked:
            builder = builder.add_revoked_certificate(
                x509.RevokedCertificateBuilder().serial_number(cert.serial_number).revocation_date(this_update).build()
            )
        return builder.sign(self.root_key, hashes.SHA256()).public_bytes(serialization.Encoding.DER)

    def ocsp(self, cert, this_update: Optional[dt.datetime] = None, next_update_days: int = 7) -> bytes:
        from cryptography.hazmat.primitives import serialization
        from cryptography.x509 import ocsp

        this_update = (this_update or (self.now - dt.timedelta(minutes=5))).replace(microsecond=0)
        builder = (
            ocsp.OCSPResponseBuilder()
            .add_response(
                cert=cert,
                issuer=self.root,
                algorithm=self._hashes.SHA1(),
                cert_status=ocsp.OCSPCertStatus.GOOD,
                this_update=this_update,
                next_update=this_update + dt.timedelta(days=next_update_days),
                revocation_time=None,
                revocation_reason=None,
            )
            .responder_id(ocsp.OCSPResponderEncoding.HASH, self.root)
        )
        return builder.sign(self.root_key, self._hashes.SHA256()).public_bytes(serialization.Encoding.DER)

    def write_pfx(self, path: Path, key, cert, pass_env: str, friendly: bytes) -> None:
        from cryptography.hazmat.primitives import serialization
        from cryptography.hazmat.primitives.serialization import pkcs12

        from pdftool.certs import read_passphrase

        passphrase = read_passphrase(pass_env)
        try:
            data = pkcs12.serialize_key_and_certificates(
                name=friendly, key=key, cert=cert, cas=[self.root], encryption_algorithm=serialization.BestAvailableEncryption(passphrase.encode("utf-8"))
            )
        finally:
            del passphrase
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)

    @staticmethod
    def pem(cert) -> bytes:
        from cryptography.hazmat.primitives import serialization

        return cert.public_bytes(serialization.Encoding.PEM)


def generate_test_pki(out_dir: Path, pass_env: str, days: int = 30, tsa_days: Optional[int] = None) -> Dict[str, Any]:
    from pdftool.certs import cert_summary, read_passphrase

    read_passphrase(pass_env)  # fail early (missing_passphrase) before generating anything
    if days < 1 or days > 3650:
        raise UsageError("invalid_days", "--days must be between 1 and 3650")
    tsa_days = tsa_days or days
    if tsa_days < 1 or tsa_days > 3650:
        raise UsageError("invalid_days", "--tsa-days must be between 1 and 3650")
    pki = TestPki(days=max(days, tsa_days))
    signer_key, signer_cert = pki.signer(days=days)
    tsa_key, tsa_cert = pki.tsa(days=tsa_days)
    files = {
        "root_pem": out_dir / "raiz-teste.pem",
        "signer_pfx": out_dir / "assinante-teste.pfx",
        "signer_pem": out_dir / "assinante-teste.pem",
        "tsa_pfx": out_dir / "tsa-teste.pfx",
        "tsa_chain_pem": out_dir / "tsa-cadeia-teste.pem",
        "crl": out_dir / "raiz-teste.crl",
        "signer_ocsp": out_dir / "assinante-teste.ocsp",
        "tsa_ocsp": out_dir / "tsa-teste.ocsp",
    }
    try:
        out_dir.mkdir(parents=True, exist_ok=True)
        files["root_pem"].write_bytes(pki.pem(pki.root))
        pki.write_pfx(files["signer_pfx"], signer_key, signer_cert, pass_env, b"AssinaVelox assinante LTV TESTE")
        files["signer_pem"].write_bytes(pki.pem(signer_cert))
        pki.write_pfx(files["tsa_pfx"], tsa_key, tsa_cert, pass_env, b"AssinaVelox TSA LTV TESTE")
        files["tsa_chain_pem"].write_bytes(pki.pem(tsa_cert) + pki.pem(pki.root))
        files["crl"].write_bytes(pki.crl(next_update_days=days))
        files["signer_ocsp"].write_bytes(pki.ocsp(signer_cert))
        files["tsa_ocsp"].write_bytes(pki.ocsp(tsa_cert))
    except OSError as exc:
        raise ProcessingError("write_failed", f"could not write the test PKI: {type(exc).__name__}") from exc
    finally:
        del signer_key, tsa_key
    return {
        "ok": True,
        "test_only": True,
        "warning": "PKI de TESTE: não é ICP-Brasil, não tem validade jurídica e nunca deve ser usada em produção.",
        "files": {key: str(path) for key, path in files.items()},
        "signer_subject": cert_summary(signer_cert)["subject"],
        "signer_not_after": cert_summary(signer_cert)["not_after"],
        "tsa_subject": cert_summary(tsa_cert)["subject"],
        "tsa_not_after": cert_summary(tsa_cert)["not_after"],
        "root_subject": cert_summary(pki.root)["subject"],
    }


# --------------------------------------------------------------------------- CLI registration


def _tsa_spec_from_args(args) -> TsaSpec:
    return TsaSpec(
        kind=args.tsa_kind,
        pfx=args.tsa_pfx,
        pass_env=args.tsa_pass_env,
        serials=list(args.tsa_serial or []),
        policy_oid=args.tsa_policy_oid,
        accuracy_ms=args.tsa_accuracy_ms,
        url=args.tsa_url,
        token_env=args.tsa_token_env,
        timeout=args.tsa_timeout,
    )


def _cmd_ltv_sign(args):
    return ltv_sign(
        args.input,
        args.output,
        args.pfx,
        args.pass_env,
        args.level,
        _tsa_spec_from_args(args),
        field_name=args.field_name,
        reason=args.reason,
        location=args.location,
        trust_paths=args.trust,
        crl_paths=args.crl,
        ocsp_paths=args.ocsp,
        allow_fetching=args.allow_fetching,
        revocation_mode=args.revocation_mode,
    )


def _cmd_ltv_refresh(args):
    return ltv_refresh(
        args.input,
        args.output,
        _tsa_spec_from_args(args),
        trust_paths=args.trust,
        crl_paths=args.crl,
        ocsp_paths=args.ocsp,
        allow_fetching=args.allow_fetching,
        revocation_mode=args.revocation_mode,
    )


def _cmd_ltv_validate(args):
    return ltv_validate(args.input, args.trust, args.tsa_trust, args.crl, args.ocsp, at=args.at)


def _cmd_ltv_gen_test_pki(args):
    return generate_test_pki(args.out_dir, args.pass_env, days=args.days, tsa_days=args.tsa_days)


def _add_tsa_options(p, path_type) -> None:
    p.add_argument("--tsa-kind", dest="tsa_kind", default="operator", help="operator | commercial (declared by the caller, never icp_brasil)")
    p.add_argument("--tsa-pfx", dest="tsa_pfx", type=path_type, default=None, help="operator TSA PKCS#12 (in process)")
    p.add_argument("--tsa-pass-env", dest="tsa_pass_env", default=None, help="NAME of the env var with the TSA PKCS#12 passphrase")
    p.add_argument("--tsa-serial", dest="tsa_serial", type=int, action="append", default=[], help="serial allocated by the caller; one per token, repeatable")
    p.add_argument("--tsa-policy-oid", dest="tsa_policy_oid", default=None)
    p.add_argument("--tsa-accuracy-ms", dest="tsa_accuracy_ms", type=int, default=1000)
    p.add_argument("--tsa-url", dest="tsa_url", default=None, help="RFC 3161 HTTP TSA (instead of --tsa-pfx)")
    p.add_argument("--tsa-token-env", dest="tsa_token_env", default=None, help="NAME of the env var with a Bearer token for --tsa-url")
    p.add_argument("--tsa-timeout", dest="tsa_timeout", type=int, default=10)


def _add_revinfo_options(p, path_type) -> None:
    p.add_argument("--trust", action="append", type=path_type, default=[], help="trusted root (PEM/DER); repeatable")
    p.add_argument("--crl", action="append", type=path_type, default=[], help="CRL (DER/PEM) handed over offline; repeatable")
    p.add_argument("--ocsp", action="append", type=path_type, default=[], help="OCSP response (DER) handed over offline; repeatable")


def add_ltv_commands(sub, path_type) -> None:
    """P3-LTV subcommands. Called once from ``cli.build_parser`` (additive registration)."""
    p = sub.add_parser("ltv-sign", help="PAdES signature with signature time stamp (B-T), DSS (B-LT) and document time stamp (B-LTA); announced profile stays B-B")
    p.add_argument("--in", dest="input", type=path_type, required=True)
    p.add_argument("--out", dest="output", type=path_type, required=True)
    p.add_argument("--pfx", type=path_type, required=True)
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the env var with the PKCS#12 passphrase")
    p.add_argument("--level", required=True, choices=list(LEVELS[1:]))
    p.add_argument("--field-name", dest="field_name", default=None)
    p.add_argument("--reason", default=None)
    p.add_argument("--location", default=None)
    _add_tsa_options(p, path_type)
    _add_revinfo_options(p, path_type)
    p.add_argument("--allow-fetching", dest="allow_fetching", action="store_true", help="PRODUCTION ONLY: fetch CRL/OCSP over the network")
    p.add_argument("--revocation-mode", dest="revocation_mode", default="hard-fail", choices=list(REVOCATION_MODES))
    p.set_defaults(func=_cmd_ltv_sign)

    p = sub.add_parser("ltv-refresh", help="archive re-stamping: DSS for the last time stamp + a new document time stamp")
    p.add_argument("--in", dest="input", type=path_type, required=True)
    p.add_argument("--out", dest="output", type=path_type, required=True)
    _add_tsa_options(p, path_type)
    _add_revinfo_options(p, path_type)
    p.add_argument("--allow-fetching", dest="allow_fetching", action="store_true", help="PRODUCTION ONLY: fetch CRL/OCSP over the network")
    p.add_argument("--revocation-mode", dest="revocation_mode", default="hard-fail", choices=list(REVOCATION_MODES))
    p.set_defaults(func=_cmd_ltv_refresh)

    p = sub.add_parser("ltv-validate", help="report the PAdES level effectively present, DSS/VRI, revocation coverage and the time stamp chain")
    p.add_argument("--in", dest="input", type=path_type, required=True)
    _add_revinfo_options(p, path_type)
    p.add_argument("--tsa-trust", dest="tsa_trust", action="append", type=path_type, default=[], help="trusted TSA root, when different from --trust")
    p.add_argument("--at", default=None, help="validation time (ISO-8601); default: now")
    p.set_defaults(func=_cmd_ltv_validate)

    p = sub.add_parser("ltv-gen-test-pki", help="TEST PKI (root, signer, TSA, CRL, OCSP) for offline B-LT tests - never for production")
    p.add_argument("--out-dir", dest="out_dir", type=path_type, required=True)
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the env var holding the PKCS#12 passphrase")
    p.add_argument("--days", type=int, default=30)
    p.add_argument("--tsa-days", dest="tsa_days", type=int, default=None)
    p.set_defaults(func=_cmd_ltv_gen_test_pki)
