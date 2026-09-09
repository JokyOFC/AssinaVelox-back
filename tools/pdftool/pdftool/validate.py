"""``validate`` command: verify every embedded signature with pyHanko."""

from __future__ import annotations

import datetime as dt
import logging
from pathlib import Path
from typing import Any, Dict, List, Optional

from asn1crypto import pem
from asn1crypto import x509 as asn1_x509
from pyhanko.pdf_utils.crypt.api import AuthStatus
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.diff_analysis import SuspiciousModification
from pyhanko.sign.validation import validate_pdf_signature
from pyhanko.sign.validation.settings import KeyUsageConstraints
from pyhanko_certvalidator import ValidationContext

from pdftool.certs import cert_from_asn1, cert_summary
from pdftool.errors import InputRejected, UsageError

log = logging.getLogger(__name__)

# Accept certificates that carry nonRepudiation OR digitalSignature.
KEY_USAGE = KeyUsageConstraints(key_usage={"non_repudiation", "digital_signature"}, match_all_key_usages=False)
REVOCATION_STATUS = "not_checked"


def load_trust_roots(paths: List[Path]) -> List[asn1_x509.Certificate]:
    roots: List[asn1_x509.Certificate] = []
    for path in paths:
        if not path.is_file():
            raise UsageError("trust_file_not_found", f"trust file not found: {path}")
        data = path.read_bytes()
        try:
            if pem.detect(data):
                for _type, _headers, der in pem.unarmor(data, multiple=True):
                    roots.append(asn1_x509.Certificate.load(der))
            else:
                roots.append(asn1_x509.Certificate.load(data))
        except Exception as exc:  # noqa: BLE001
            raise UsageError("invalid_trust_file", f"cannot parse certificate(s) in {path.name}: {type(exc).__name__}") from exc
    if not roots:
        raise UsageError("invalid_trust_file", "no certificates found in the trust files")
    return roots


def _iso(value) -> Optional[str]:
    if value is None:
        return None
    if isinstance(value, dt.datetime):
        if value.tzinfo is None:
            value = value.replace(tzinfo=dt.timezone.utc)
        return value.astimezone(dt.timezone.utc).isoformat(timespec="seconds")
    return str(value)


def _indic_name(indic) -> Optional[str]:
    if indic is None:
        return None
    return getattr(indic, "name", None) or str(indic)


def _open(path: Path):
    if not path.is_file():
        raise InputRejected("missing_input", f"input file not found: {path}")
    handle = path.open("rb")
    try:
        reader = PdfFileReader(handle, strict=False)
        if reader.encrypted:
            try:
                result = reader.decrypt("")
            except Exception as exc:  # noqa: BLE001
                raise InputRejected("encrypted_pdf", f"PDF is encrypted and cannot be opened: {type(exc).__name__}") from exc
            if result.status == AuthStatus.FAILED:
                raise InputRejected("encrypted_pdf", "PDF is encrypted; it cannot be opened without a password")
    except InputRejected:
        handle.close()
        raise
    except Exception as exc:  # noqa: BLE001
        handle.close()
        raise InputRejected("invalid_pdf", f"cannot parse PDF: {type(exc).__name__}: {exc}") from exc
    return handle, reader


def _blank_entry(field_name: str) -> Dict[str, Any]:
    return {
        "field_name": field_name,
        "intact": False,
        "valid": False,
        "trusted": False,
        "trust_reason": None,
        "signer_subject": None,
        "issuer": None,
        "serial_hex": None,
        "cert_fingerprint_sha256": None,
        "not_before": None,
        "not_after": None,
        "signing_time": None,
        "md_algorithm": None,
        "subfilter": None,
        "coverage": "UNCLEAR",
        "modification_level": None,
        "docmdp_ok": None,
        "revocation": REVOCATION_STATUS,
        "summary": "INVALID",
        "errors": [],
    }


def validate_one(sig, roots: List[asn1_x509.Certificate]) -> Dict[str, Any]:
    has_roots = bool(roots)
    entry = _blank_entry(str(sig.field_name))
    errors: List[str] = entry["errors"]

    try:
        subfilter = sig.sig_object.get("/SubFilter")
        entry["subfilter"] = str(subfilter) if subfilter is not None else None
    except Exception:  # noqa: BLE001
        pass

    cert = None
    try:
        cert = sig.signer_cert
    except Exception as exc:  # noqa: BLE001
        errors.append(f"signer_certificate_unavailable: {type(exc).__name__}")
    if cert is not None:
        try:
            summary = cert_summary(cert_from_asn1(cert))
            entry["signer_subject"] = summary["subject"]
            entry["issuer"] = summary["issuer"]
            for key in ("serial_hex", "cert_fingerprint_sha256", "not_before", "not_after"):
                entry[key] = summary[key]
        except Exception:  # noqa: BLE001 - fall back to asn1crypto's rendering
            entry["signer_subject"] = cert.subject.human_friendly
            entry["issuer"] = cert.issuer.human_friendly

    # Without configured trust roots we anchor on the signer's own certificate
    # so the cryptographic/integrity checks can run; trust is then reported as
    # False with an explicit reason.
    anchors = roots if has_roots else ([cert] if cert is not None else [])
    context = ValidationContext(trust_roots=anchors, allow_fetching=False, revocation_mode="soft-fail")
    try:
        status = validate_pdf_signature(sig, signer_validation_context=context, key_usage_settings=KEY_USAGE)
    except Exception as exc:  # noqa: BLE001
        errors.append(f"validation_error: {type(exc).__name__}: {exc}")
        entry["trust_reason"] = "validation_error" if has_roots else "no_trust_roots_configured"
        return entry

    entry["intact"] = bool(status.intact)
    entry["valid"] = bool(status.valid)
    entry["md_algorithm"] = status.md_algorithm
    entry["coverage"] = status.coverage.name if status.coverage is not None else "UNCLEAR"
    level = status.modification_level
    entry["modification_level"] = level.name if level is not None else None
    entry["docmdp_ok"] = status.docmdp_ok
    entry["summary"] = status.summary()
    signing_time = status.signer_reported_dt
    if signing_time is None:
        try:
            signing_time = sig.self_reported_timestamp
        except Exception:  # noqa: BLE001
            signing_time = None
    entry["signing_time"] = _iso(signing_time)

    indic = status.trust_problem_indic
    if has_roots:
        trusted = bool(status.trusted)
        entry["trusted"] = trusted
        if not trusted:
            if indic is not None:
                entry["trust_reason"] = _indic_name(indic)
            elif not (status.valid and status.intact):
                entry["trust_reason"] = "signature_invalid"
            else:
                entry["trust_reason"] = "no_validation_path"
            errors.append(f"trust: {entry['trust_reason']}")
    else:
        entry["trusted"] = False
        entry["trust_reason"] = "no_trust_roots_configured"
        if indic is not None:
            errors.append(f"self_anchored_chain_problem: {_indic_name(indic)}")

    if not status.intact:
        errors.append("digest_mismatch: the bytes covered by the signature were modified")
    if not status.valid:
        errors.append("invalid_signature: cryptographic verification failed")
    if isinstance(status.diff_result, SuspiciousModification):
        errors.append(f"suspicious_modification: {status.diff_result}")
    if status.docmdp_ok is False:
        errors.append("docmdp_violation: later changes exceed the permitted DocMDP level")
    return entry


def validate_pdf(in_path: Path, trust_paths: Optional[List[Path]] = None, check_revocation: bool = False) -> Dict[str, Any]:
    # Revocation checking (CRL/OCSP) is deliberately not implemented: it would
    # require network access at run time. The flag is accepted for forward
    # compatibility and the result always states "not_checked".
    roots = load_trust_roots(trust_paths) if trust_paths else []
    handle, reader = _open(in_path)
    try:
        try:
            sigs = list(reader.embedded_regular_signatures)
        except Exception as exc:  # noqa: BLE001
            raise InputRejected("invalid_pdf", f"cannot enumerate signatures: {type(exc).__name__}: {exc}") from exc
        results = [validate_one(sig, roots) for sig in sigs]
    finally:
        handle.close()
    return {
        "ok": True,
        "signature_count": len(results),
        # ``intact`` answers "were the bytes COVERED by the signature modified?" — it says
        # nothing about bytes that were appended AFTER the signed revision. A PDF with an
        # incremental update tacked on stays intact/valid/trusted; the only tell is
        # ``coverage`` dropping from ENTIRE_FILE to ENTIRE_REVISION (plus, when the change
        # touches the document catalog, ``modification_level`` and ``docmdp_ok``). Those
        # facts were collected per signature and read by nobody, so a caller that only
        # looked at ``all_intact`` published "no change after the signature" over a file
        # that had content outside the signed revision. They are now aggregated here, next
        # to the flags they qualify. ``all_intact`` keeps its own, narrower meaning.
        "all_intact": bool(results) and all(r["intact"] for r in results),
        "all_valid": bool(results) and all(r["intact"] and r["valid"] for r in results),
        "all_covering": bool(results) and all(r["coverage"] == "ENTIRE_FILE" for r in results),
        "all_docmdp_ok": bool(results) and all(r["docmdp_ok"] is not False for r in results),
        "trust_roots_configured": len(roots),
        "revocation": REVOCATION_STATUS,
        "signatures": results,
    }
