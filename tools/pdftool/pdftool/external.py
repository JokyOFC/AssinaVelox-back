"""``prepare-external`` / ``embed-external``: PAdES B-B signature made OUTSIDE this process.

Roadmap §3.4 (certificate A3 through a local component) and the correction in
``docs/fases-2-3-viabilidade.md`` §6 item 1: the signing key never reaches the server.
The server prepares the pending revision and a digest; a local component (token/smart
card) or an external service signs; the server embeds and validates.

This is pyHanko's documented *interrupted signing* flow
(``PdfSigner.async_digest_doc_for_signing`` -> ``ExternalSigner.signed_attrs`` -> raw
signature over ``signed_attrs.dump()`` -> ``async_sign_prescribed_attributes`` ->
fill the reserved ``/Contents``), split across two processes.

``prepare-external``
    On the CURRENT revision (after the frozen base and any previous signatures, same order
    as the incremental pipeline of roadmap §2.12) writes the pending revision with an empty
    signature placeholder and returns:

    - ``digest_to_sign`` — SHA-256 of the DER of the signed attributes (mode ``raw``: what a
      component such as NexU ``/v1/sign`` or Lacuna ``signHash`` signs, without re-hashing);
    - ``document_digest`` — SHA-256 over the ``/ByteRange`` (mode ``cms``: what a service that
      returns a ready CMS/PKCS#7 signs).

    It also writes a minimal STATE file, with no secret in it: digests, the reserved region,
    the DER of the signed attributes, fingerprints and the hashes/sizes of the base and of the
    pending revision. No key, no passphrase, no token handle.

``embed-external``
    Receives either (a) the raw signature + the signer certificate (+ chain) or (b) a ready
    CMS/PKCS#7 (SignedData, detached), checks that it signs exactly this pending revision with
    exactly the announced certificate, embeds it, and validates the WHOLE file: every
    signature intact and valid, the new one covering the entire file, earlier revisions
    preserved byte for byte, only permitted changes after each earlier signature.

Refused (stable ``error.code``):

- ``state_invalid`` (2)          state file unreadable, wrong format or inconsistent
- ``pending_mismatch`` (4)       the pending file is not the one described by the state
- ``certificate_mismatch`` (4)   certificate different from the one announced at prepare time
- ``signature_invalid`` (4)      the raw signature (or the CMS signature) does not verify
- ``digest_mismatch`` (4)        the CMS signs another document digest (another revision)
- ``cms_invalid`` (4)            not a detached CMS SignedData with one signer
- ``cms_too_large`` (4)          the CMS does not fit the reserved placeholder
- ``revision_chain_broken`` (3)  the embedded result does not validate as a sound chain
- plus the certificate checks of ``inspect-cert`` (``certificate_expired``, ...).

Profile: PAdES-B-B only. No time-stamp, no LTV, no revocation check (offline tool).
"""

from __future__ import annotations

import asyncio
import base64
import binascii
import datetime as dt
import hashlib
import json
import logging
import shutil
from pathlib import Path
from typing import Any, Dict, List, Optional

from asn1crypto import algos as asn1_algos
from asn1crypto import cms as asn1_cms
from asn1crypto import pem as asn1_pem
from asn1crypto import x509 as asn1_x509
from cryptography import x509
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, padding, rsa
from cryptography.hazmat.primitives.asymmetric.utils import Prehashed
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.misc import PdfError
from pyhanko.sign import fields, signers
from pyhanko.sign.general import SigningError
from pyhanko.sign.signers.pdf_byterange import PreparedByteRangeDigest
from pyhanko_certvalidator import CertificateValidator, ValidationContext
from pyhanko_certvalidator.registry import SimpleCertificateStore

from pdftool.certs import cert_summary
from pdftool.compose import reject_same_path
from pdftool.errors import InputRejected, ProcessingError, UsageError
from pdftool.inspect_cert import (
    ICP_BRASIL_POLICY_ARC,
    SIGNING_EKUS,
    _common_name,
    _extended_key_usage,
    _is_ca,
    _is_test_certificate,
    _key_description,
    _key_usage,
    _policy_oids,
    holder_facts,
)
from pdftool.inspect_cmd import close_reader, open_reader
from pdftool.participant_sign import FIELD_NAME_RE, _existing_field_names, analyse_chain
from pdftool.validate import load_trust_roots, validate_pdf

log = logging.getLogger(__name__)

PROFILE = "PAdES-B-B"
STATE_FORMAT = "assinavelox.external-signature.v1"
MD_ALGORITHM = "sha256"
HASH_FUNCTION = "SHA256"
DEFAULT_BYTES_RESERVED = 16384
MIN_BYTES_RESERVED = 4096
MAX_BYTES_RESERVED = 65536
MAX_CERT_BYTES = 64 * 1024
MAX_RAW_SIGNATURE_BYTES = 2048
MAX_CMS_BYTES = MAX_BYTES_RESERVED
REVOCATION_STATUS = "not_checked"

# ICP-Brasil certificate-type arcs inside the policy OID (DOC-ICP-04, as understood by this
# project; NOT CONFIRMED against the current text in this wave). A policy OID is a CLAIM
# made by the certificate itself: only a chain validated up to a pinned ICP-Brasil root
# (plus revocation) would turn it into a fact, and this tool does neither.
_ICP_TYPE_ARCS = {"1": "A1", "2": "A2", "3": "A3", "4": "A4"}

_STATE_KEYS = (
    "format", "field_name", "md_algorithm", "signature_mechanism", "document_digest_hex",
    "reserved_region_start", "reserved_region_end", "signed_attrs_der_b64", "signed_attrs_digest_hex",
    "signer_cert_fingerprint_sha256", "base_size", "base_sha256", "pending_size", "pending_sha256",
    "previous_signature_count",
)


# --------------------------------------------------------------------------------------
# certificates
# --------------------------------------------------------------------------------------

def _read_limited(path: Path, limit: int, what: str) -> bytes:
    if not path.is_file():
        raise InputRejected("missing_input", f"{what} nao encontrado")
    try:
        if path.stat().st_size > limit:
            raise InputRejected("input_too_large", f"{what} passa de {limit // 1024} KB")
        return path.read_bytes()
    except OSError as exc:
        raise ProcessingError("read_failed", f"nao foi possivel ler {what}: {type(exc).__name__}") from exc


def load_certificates(path: Path, what: str = "certificado") -> List[x509.Certificate]:
    """PEM (one or many) or DER. Raises ``certificate_invalid`` for anything else."""
    data = _read_limited(path, MAX_CERT_BYTES, what)
    try:
        if asn1_pem.detect(data):
            ders = [der for _type, _headers, der in asn1_pem.unarmor(data, multiple=True)]
        else:
            ders = [data]
        certs = [x509.load_der_x509_certificate(der) for der in ders]
    except Exception as exc:  # noqa: BLE001
        raise InputRejected("certificate_invalid", f"{what} nao e um certificado X.509 legivel") from exc
    if not certs:
        raise InputRejected("certificate_invalid", f"{what} vazio")
    return certs


def _asn1(cert: x509.Certificate) -> asn1_x509.Certificate:
    return asn1_x509.Certificate.load(cert.public_bytes(serialization.Encoding.DER))


def _fingerprint(cert: x509.Certificate) -> str:
    return cert.fingerprint(hashes.SHA256()).hex()


def check_signer_certificate(cert: x509.Certificate, at: Optional[dt.datetime] = None) -> None:
    """The checks ``inspect-cert`` makes, minus the ones that need the private key."""
    if _is_ca(cert):
        raise InputRejected("certificate_is_ca", "o certificado e de uma autoridade certificadora, nao de um titular")
    now = at or dt.datetime.now(dt.timezone.utc)
    if cert.not_valid_before_utc > now:
        raise InputRejected("certificate_not_yet_valid", "o certificado ainda nao esta valido")
    if cert.not_valid_after_utc < now:
        raise InputRejected("certificate_expired", "o certificado esta vencido")
    key_usage = _key_usage(cert)
    if key_usage is not None and not ({"digital_signature", "non_repudiation"} & set(key_usage)):
        raise InputRejected("certificate_not_for_signing", "o uso de chave do certificado nao permite assinatura digital")
    eku = _extended_key_usage(cert)
    if eku is not None and not any(item["oid"] in SIGNING_EKUS for item in eku):
        raise InputRejected("certificate_not_for_signing", "o uso estendido do certificado nao permite assinar documentos")
    if not isinstance(cert.public_key(), (rsa.RSAPublicKey, ec.EllipticCurvePublicKey)):
        raise InputRejected("unsupported_key_algorithm", "somente chaves RSA e EC sao aceitas")


def declared_icp_type(policy_oids: List[str]) -> Optional[str]:
    for oid in policy_oids:
        if oid.startswith(ICP_BRASIL_POLICY_ARC):
            arc = oid[len(ICP_BRASIL_POLICY_ARC):].split(".", 1)[0]
            if arc in _ICP_TYPE_ARCS:
                return _ICP_TYPE_ARCS[arc]
    return None


def describe_certificate(cert: x509.Certificate, chain: List[x509.Certificate]) -> Dict[str, Any]:
    """Public facts only (safe to print and to store)."""
    policies = _policy_oids(cert)
    icp_type = declared_icp_type(policies)
    test = _is_test_certificate(cert)
    return {
        **cert_summary(cert),
        "subject_cn": _common_name(cert.subject),
        "issuer_cn": _common_name(cert.issuer),
        **_key_description(cert.public_key()),
        "key_usage": _key_usage(cert),
        "extended_key_usage": _extended_key_usage(cert),
        "self_signed": cert.subject == cert.issuer,
        "chain_length": len(chain),
        "chain_fingerprints_sha256": [_fingerprint(c) for c in chain],
        "holder": holder_facts(cert),
        "icp_brasil": {
            "policy_oids": policies,
            "declares_icp_brasil_policy": any(oid.startswith(ICP_BRASIL_POLICY_ARC) for oid in policies),
            # What the certificate SAYS about its media (A3 = token/smart card). A claim.
            "declared_certificate_type": icp_type,
            "chain_validated": False,
        },
        "test_certificate": test,
    }


def evaluate_chain(cert: x509.Certificate, chain: List[x509.Certificate], trust_paths: Optional[List[Path]]) -> Dict[str, Any]:
    """Offline path validation against the given anchors. Never fetches, never checks revocation."""
    if not trust_paths:
        return {
            "trusted": False,
            "reason": "no_trust_roots_configured",
            "trust_roots_configured": 0,
            "revocation": REVOCATION_STATUS,
        }
    roots = load_trust_roots(trust_paths)
    context = ValidationContext(
        trust_roots=roots,
        other_certs=[_asn1(c) for c in chain],
        allow_fetching=False,
        revocation_mode="soft-fail",
    )
    validator = CertificateValidator(_asn1(cert), intermediate_certs=[_asn1(c) for c in chain], validation_context=context)
    try:
        asyncio.run(validator.async_validate_usage(key_usage=set()))
        trusted, reason = True, None
    except Exception as exc:  # noqa: BLE001 - PathBuildingError, PathValidationError, ...
        trusted, reason = False, type(exc).__name__
    return {
        "trusted": trusted,
        "reason": reason,
        "trust_roots_configured": len(roots),
        "revocation": REVOCATION_STATUS,
    }


def _mechanism(cert: x509.Certificate, prefer_pss: bool) -> asn1_algos.SignedDigestAlgorithm:
    key = cert.public_key()
    if isinstance(key, ec.EllipticCurvePublicKey):
        return asn1_algos.SignedDigestAlgorithm({"algorithm": "sha256_ecdsa"})
    if prefer_pss:
        return asn1_algos.SignedDigestAlgorithm({
            "algorithm": "rsassa_pss",
            "parameters": asn1_algos.RSASSAPSSParams({
                "hash_algorithm": asn1_algos.DigestAlgorithm({"algorithm": "sha256"}),
                "mask_gen_algorithm": asn1_algos.MaskGenAlgorithm({
                    "algorithm": "mgf1",
                    "parameters": asn1_algos.DigestAlgorithm({"algorithm": "sha256"}),
                }),
                "salt_length": 32,
            }),
        })
    return asn1_algos.SignedDigestAlgorithm({"algorithm": "sha256_rsa"})


def verify_signature(cert: x509.Certificate, algo: str, hash_name: str, data: bytes, signature: bytes) -> bool:
    """Verify ``signature`` over ``data`` with the certificate's public key."""
    hash_cls = {"sha256": hashes.SHA256, "sha384": hashes.SHA384, "sha512": hashes.SHA512}.get(hash_name)
    if hash_cls is None:
        return False
    key = cert.public_key()
    try:
        if isinstance(key, rsa.RSAPublicKey) and algo == "rsassa_pkcs1v15":
            key.verify(signature, data, padding.PKCS1v15(), hash_cls())
        elif isinstance(key, rsa.RSAPublicKey) and algo == "rsassa_pss":
            key.verify(signature, data, padding.PSS(mgf=padding.MGF1(hash_cls()), salt_length=padding.PSS.AUTO), hash_cls())
        elif isinstance(key, ec.EllipticCurvePublicKey) and algo == "ecdsa":
            key.verify(signature, data, ec.ECDSA(hash_cls()))
        else:
            return False
    except (InvalidSignature, ValueError, TypeError):
        return False
    return True


# --------------------------------------------------------------------------------------
# helpers
# --------------------------------------------------------------------------------------

def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _byte_range_digest(data: bytes, start: int, end: int) -> bytes:
    digest = hashlib.sha256()
    digest.update(data[:start])
    digest.update(data[end:])
    return digest.digest()


def _preflight(in_path: Path) -> Dict[str, Any]:
    """Refuse corrupt/encrypted input and a document whose existing signatures are unsound."""
    reader, info = open_reader(in_path)
    try:
        if info["encrypted"]:
            raise InputRejected("encrypted_pdf", "signing encrypted PDFs is not supported")
    finally:
        close_reader(reader)
    before = validate_pdf(in_path)
    for sig in before["signatures"]:
        if not (sig["intact"] and sig["valid"]):
            raise InputRejected(
                "previous_signature_invalid",
                f"signature {sig['field_name']!r} already in the document is not intact/valid; refusing to sign on top of it",
            )
    if before["signature_count"] and not analyse_chain(before)["ok"]:
        raise InputRejected("previous_signature_invalid", "the signatures already in the document do not form a sound chain")
    return before


def _check_bytes_reserved(value: int) -> int:
    if not MIN_BYTES_RESERVED <= value <= MAX_BYTES_RESERVED:
        raise UsageError("invalid_bytes_reserved", f"--bytes-reserved must be between {MIN_BYTES_RESERVED} and {MAX_BYTES_RESERVED}")
    return value


def _write_json(path: Path, obj: Dict[str, Any]) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(obj, ensure_ascii=False, sort_keys=True), encoding="utf-8")
    except OSError as exc:
        raise ProcessingError("write_failed", f"could not write the state file: {exc}") from exc


def load_state(path: Path) -> Dict[str, Any]:
    if not path.is_file():
        raise UsageError("state_invalid", "state file not found")
    try:
        if path.stat().st_size > 256 * 1024:
            raise UsageError("state_invalid", "state file too large")
        state = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise UsageError("state_invalid", f"state file unreadable: {type(exc).__name__}") from exc
    if not isinstance(state, dict) or state.get("format") != STATE_FORMAT:
        raise UsageError("state_invalid", "state file has an unknown format")
    missing = [key for key in _STATE_KEYS if key not in state]
    if missing:
        raise UsageError("state_invalid", f"state file is missing: {', '.join(missing)}")
    try:
        signed_attrs_der = base64.b64decode(state["signed_attrs_der_b64"], validate=True)
        bytes.fromhex(state["document_digest_hex"])
        start, end = int(state["reserved_region_start"]), int(state["reserved_region_end"])
    except (ValueError, TypeError, binascii.Error) as exc:
        raise UsageError("state_invalid", "state file has malformed values") from exc
    if hashlib.sha256(signed_attrs_der).hexdigest() != state["signed_attrs_digest_hex"]:
        raise UsageError("state_invalid", "signed attributes do not match their digest")
    if not 0 < start < end or state["md_algorithm"] != MD_ALGORITHM:
        raise UsageError("state_invalid", "state file is inconsistent")
    return state


# --------------------------------------------------------------------------------------
# prepare-external
# --------------------------------------------------------------------------------------

def prepare_external(
    in_path: Path,
    out_path: Path,
    state_out: Path,
    cert_path: Path,
    chain_paths: Optional[List[Path]] = None,
    field_name: str = "",
    reason: Optional[str] = None,
    location: Optional[str] = None,
    trust_paths: Optional[List[Path]] = None,
    bytes_reserved: int = DEFAULT_BYTES_RESERVED,
    prefer_pss: bool = False,
    expect_fingerprint: Optional[str] = None,
) -> Dict[str, Any]:
    if not FIELD_NAME_RE.match(field_name or ""):
        raise UsageError("invalid_field_name", "--field-name must match [A-Za-z0-9_.-]{1,100}")
    reject_same_path(in_path, out_path)
    reject_same_path(in_path, state_out)
    reject_same_path(out_path, state_out)
    bytes_reserved = _check_bytes_reserved(bytes_reserved)

    signer_certs = load_certificates(cert_path, "certificado do signatario")
    cert = signer_certs[0]
    chain: List[x509.Certificate] = list(signer_certs[1:])
    for extra in chain_paths or []:
        chain.extend(load_certificates(extra, "certificado da cadeia"))
    fingerprint = _fingerprint(cert)
    if expect_fingerprint and fingerprint != expect_fingerprint.strip().lower():
        raise InputRejected("certificate_mismatch", "o certificado nao e o anunciado")
    check_signer_certificate(cert)
    chain = [c for c in chain if _fingerprint(c) != fingerprint]

    before = _preflight(in_path)
    if field_name in _existing_field_names(in_path):
        raise UsageError("field_name_taken", f"signature field {field_name!r} already exists in the document")

    trust = evaluate_chain(cert, chain, trust_paths)
    mechanism = _mechanism(cert, prefer_pss)
    signing_cert = _asn1(cert)
    registry = SimpleCertificateStore.from_certs([signing_cert, *[_asn1(c) for c in chain]])
    placeholder = cert.public_key().key_size // 8 if isinstance(cert.public_key(), rsa.RSAPublicKey) else 72
    ext_signer = signers.ExternalSigner(
        signing_cert=signing_cert,
        cert_registry=registry,
        signature_value=placeholder,
        signature_mechanism=mechanism,
        prefer_pss=prefer_pss,
    )
    meta = signers.PdfSignatureMetadata(
        field_name=field_name,
        md_algorithm=MD_ALGORITHM,
        subfilter=fields.SigSeedSubFilter.PADES,
        certify=False,  # approval signature: later participants and the operator can still sign
        use_pades_lta=False,
        embed_validation_info=False,
        reason=reason or None,
        location=location or None,
    )
    pdf_signer = signers.PdfSigner(meta, ext_signer, new_field_spec=fields.SigFieldSpec(sig_field_name=field_name))

    async def _prepare():
        with in_path.open("rb") as inf:
            try:
                writer = IncrementalPdfFileWriter(inf, strict=False)
            except Exception as exc:  # noqa: BLE001
                raise InputRejected("invalid_pdf", f"pyHanko cannot parse PDF: {type(exc).__name__}") from exc
            with out_path.open("wb") as outf:
                prep, _tbs, _handle = await pdf_signer.async_digest_doc_for_signing(
                    writer, bytes_reserved=bytes_reserved, output=outf
                )
                attrs = await ext_signer.signed_attrs(prep.document_digest, MD_ALGORITHM, use_pades=True)
        return prep, attrs

    out_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        prep, signed_attrs = asyncio.run(_prepare())
    except (InputRejected, UsageError):
        out_path.unlink(missing_ok=True)
        raise
    except SigningError as exc:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("signing_failed", f"pyHanko refused to prepare the signature: {exc}") from exc
    except (PdfError, ValueError, TypeError, KeyError) as exc:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("signing_failed", f"preparation failed: {type(exc).__name__}: {exc}") from exc
    except OSError as exc:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("pdf_write_failed", f"could not write output: {exc}") from exc

    original = in_path.read_bytes()
    pending = out_path.read_bytes()
    if not (len(pending) > len(original) and pending[: len(original)] == original):
        out_path.unlink(missing_ok=True)
        raise ProcessingError("revision_chain_broken", "the previous revision was not preserved byte by byte")
    if _byte_range_digest(pending, prep.reserved_region_start, prep.reserved_region_end) != prep.document_digest:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("signing_failed", "the prepared byte range does not match the document digest")

    signed_attrs_der = signed_attrs.dump()
    digest_to_sign = hashlib.sha256(signed_attrs_der).hexdigest()
    state = {
        "format": STATE_FORMAT,
        "field_name": field_name,
        "md_algorithm": MD_ALGORITHM,
        "signature_mechanism": mechanism["algorithm"].native,
        "prefer_pss": bool(prefer_pss),
        "document_digest_hex": prep.document_digest.hex(),
        "reserved_region_start": prep.reserved_region_start,
        "reserved_region_end": prep.reserved_region_end,
        "bytes_reserved": bytes_reserved,
        "signed_attrs_der_b64": base64.b64encode(signed_attrs_der).decode("ascii"),
        "signed_attrs_digest_hex": digest_to_sign,
        "signer_cert_fingerprint_sha256": fingerprint,
        "chain_fingerprints_sha256": [_fingerprint(c) for c in chain],
        "base_size": len(original),
        "base_sha256": hashlib.sha256(original).hexdigest(),
        "pending_size": len(pending),
        "pending_sha256": hashlib.sha256(pending).hexdigest(),
        "previous_signature_count": before["signature_count"],
    }
    try:
        _write_json(state_out, state)
    except ProcessingError:
        out_path.unlink(missing_ok=True)
        raise

    return {
        "ok": True,
        "profile": PROFILE,
        "field_name": field_name,
        "hash_function": HASH_FUNCTION,
        "md_algorithm": MD_ALGORITHM,
        "signature_mechanism": state["signature_mechanism"],
        # mode "raw": the component signs THIS value (a digest, no re-hashing).
        "digest_to_sign_hex": digest_to_sign,
        # mode "cms": a service that returns a ready CMS signs over the document digest.
        "document_digest_hex": state["document_digest_hex"],
        "bytes_reserved": bytes_reserved,
        "previous_signature_count": before["signature_count"],
        "base_size": state["base_size"],
        "base_sha256": state["base_sha256"],
        "pending_size": state["pending_size"],
        "pending_sha256": state["pending_sha256"],
        "certificate": describe_certificate(cert, chain),
        "chain_trust": trust,
        "timestamp": None,
    }


# --------------------------------------------------------------------------------------
# embed-external
# --------------------------------------------------------------------------------------

def _load_raw_signature(path: Path) -> bytes:
    data = _read_limited(path, MAX_RAW_SIGNATURE_BYTES * 2, "assinatura")
    text = data.strip()
    # Accept the binary value or its Base64 (what NexU/Lacuna return).
    try:
        decoded = base64.b64decode(text, validate=True)
        if decoded and len(decoded) <= MAX_RAW_SIGNATURE_BYTES:
            return decoded
    except (binascii.Error, ValueError):
        pass
    if not data or len(data) > MAX_RAW_SIGNATURE_BYTES:
        raise InputRejected("signature_invalid", "assinatura vazia ou grande demais")
    return data


def _load_cms(path: Path) -> asn1_cms.ContentInfo:
    data = _read_limited(path, MAX_CMS_BYTES * 2, "CMS")
    try:
        if asn1_pem.detect(data):
            _type, _headers, data = asn1_pem.unarmor(data)
        else:
            stripped = data.strip()
            if stripped[:1] != b"\x30":
                data = base64.b64decode(stripped, validate=True)
        info = asn1_cms.ContentInfo.load(data)
        info.native  # forces full parsing
    except Exception as exc:  # noqa: BLE001
        raise InputRejected("cms_invalid", "o CMS/PKCS#7 enviado nao e legivel") from exc
    return info


def _cms_signer(info: asn1_cms.ContentInfo):
    if info["content_type"].native != "signed_data":
        raise InputRejected("cms_invalid", "o CMS nao e SignedData")
    signed_data = info["content"]
    signer_infos = signed_data["signer_infos"]
    if len(signer_infos) != 1:
        raise InputRejected("cms_invalid", "o CMS precisa ter exatamente um signatario")
    if signed_data["encap_content_info"]["content"].native is not None:
        raise InputRejected("cms_invalid", "o CMS precisa ser destacado (sem o conteudo embutido)")
    signer_info = signer_infos[0]
    sid = signer_info["sid"]
    found = None
    for choice in signed_data["certificates"] or []:
        if choice.name != "certificate":
            continue
        candidate = choice.chosen
        if sid.name == "issuer_and_serial_number":
            if candidate.issuer == sid.chosen["issuer"] and candidate.serial_number == sid.chosen["serial_number"].native:
                found = candidate
                break
        elif sid.name == "subject_key_identifier":
            if candidate.key_identifier == sid.chosen.native:
                found = candidate
                break
    if found is None:
        raise InputRejected("cms_invalid", "o CMS nao traz o certificado do signatario")
    return signer_info, x509.load_der_x509_certificate(found.dump())


def _check_pades_baseline_attrs(values: Dict[str, Any], cert: x509.Certificate) -> None:
    """PAdES baseline (ETSI EN 319 142-1 §6.3) for a ready-made CMS (review I-3A).

    - ESS signing-certificate-v2 (or v1, SHA-1) is mandatory and must hash the announced
      signer certificate: it is what binds the certificate to the signature;
    - ``signing-time`` is forbidden: in PAdES the claimed time goes in the dictionary ``/M``.
    Anything else would be embedded and announced as PAdES-B-B without being it (T2).
    """
    if values.get("signing_time"):
        raise InputRejected("cms_invalid", "o CMS traz o atributo signing-time, proibido no PAdES (a hora declarada vai no /M)")
    der = cert.public_bytes(serialization.Encoding.DER)
    ess_v2 = values.get("signing_certificate_v2")
    ess_v1 = values.get("signing_certificate")
    candidates = []
    if ess_v2:
        for cert_id in ess_v2[0]["certs"]:
            algorithm = cert_id["hash_algorithm"]["algorithm"].native
            candidates.append((algorithm, cert_id["cert_hash"].native))
    elif ess_v1:
        for cert_id in ess_v1[0]["certs"]:
            candidates.append(("sha1", cert_id["cert_hash"].native))
    else:
        raise InputRejected("cms_invalid", "o CMS nao traz o atributo ESS signing-certificate-v2, obrigatorio no PAdES")
    if not candidates:
        raise InputRejected("cms_invalid", "o atributo ESS signing-certificate do CMS esta vazio")
    algorithm, cert_hash = candidates[0]
    try:
        expected = hashlib.new(algorithm, der).digest()
    except (ValueError, TypeError) as exc:
        raise InputRejected("cms_invalid", f"algoritmo do ESS signing-certificate nao suportado: {algorithm}") from exc
    if cert_hash != expected:
        raise InputRejected("certificate_mismatch", "o ESS signing-certificate do CMS aponta outro certificado")


def _check_cms(info: asn1_cms.ContentInfo, state: Dict[str, Any]) -> x509.Certificate:
    signer_info, cert = _cms_signer(info)
    if _fingerprint(cert) != state["signer_cert_fingerprint_sha256"]:
        raise InputRejected("certificate_mismatch", "o CMS foi assinado por um certificado diferente do anunciado")
    digest_algorithm = signer_info["digest_algorithm"]["algorithm"].native
    if digest_algorithm != MD_ALGORITHM:
        raise InputRejected("digest_mismatch", f"o CMS usa {digest_algorithm}; esperado {MD_ALGORITHM}")
    attrs = signer_info["signed_attrs"]
    if not attrs:
        raise InputRejected("cms_invalid", "o CMS nao tem atributos assinados")
    values = {attr["type"].native: attr["values"] for attr in attrs}
    message_digest = values.get("message_digest")
    content_type = values.get("content_type")
    if not message_digest or message_digest[0].native != bytes.fromhex(state["document_digest_hex"]):
        raise InputRejected("digest_mismatch", "o CMS assina outro conteudo (outra revisao do documento)")
    if not content_type or content_type[0].native != "data":
        raise InputRejected("cms_invalid", "o CMS precisa declarar content-type data")
    _check_pades_baseline_attrs(values, cert)
    algo = signer_info["signature_algorithm"]
    try:
        algo_name = algo.signature_algo
    except ValueError as exc:
        raise InputRejected("cms_invalid", "algoritmo de assinatura do CMS nao suportado") from exc
    hash_name = digest_algorithm
    if algo_name == "rsassa_pss":
        hash_name = algo["parameters"]["hash_algorithm"]["algorithm"].native
    signed_bytes = attrs.untag().dump()
    if not verify_signature(cert, algo_name, hash_name, signed_bytes, signer_info["signature"].native):
        raise InputRejected("signature_invalid", "a assinatura do CMS nao confere")
    return cert


def embed_external(
    pending_path: Path,
    state_path: Path,
    out_path: Path,
    signature_path: Optional[Path] = None,
    cert_path: Optional[Path] = None,
    chain_paths: Optional[List[Path]] = None,
    cms_path: Optional[Path] = None,
    trust_paths: Optional[List[Path]] = None,
    expect_fingerprint: Optional[str] = None,
) -> Dict[str, Any]:
    if (signature_path is None) == (cms_path is None):
        raise UsageError("usage_error", "use exactly one of --signature (raw mode) or --cms (cms mode)")
    reject_same_path(pending_path, out_path)
    state = load_state(state_path)
    announced = state["signer_cert_fingerprint_sha256"]
    if expect_fingerprint and expect_fingerprint.strip().lower() != announced:
        raise InputRejected("certificate_mismatch", "o estado pendente nao e do certificado esperado")

    if not pending_path.is_file():
        raise InputRejected("missing_input", "revisao pendente nao encontrada")
    pending = pending_path.read_bytes()
    if len(pending) != int(state["pending_size"]) or hashlib.sha256(pending).hexdigest() != state["pending_sha256"]:
        raise InputRejected("pending_mismatch", "a revisao pendente nao e a descrita pelo estado")
    start, end = int(state["reserved_region_start"]), int(state["reserved_region_end"])
    if end > len(pending) or _byte_range_digest(pending, start, end).hex() != state["document_digest_hex"]:
        raise InputRejected("pending_mismatch", "o intervalo reservado da revisao pendente nao confere")
    base_size = int(state["base_size"])
    if hashlib.sha256(pending[:base_size]).hexdigest() != state["base_sha256"]:
        raise InputRejected("pending_mismatch", "a revisao pendente nao preserva a revisao-base")

    signed_attrs_der = base64.b64decode(state["signed_attrs_der_b64"])
    mode = "raw" if signature_path is not None else "cms"

    if mode == "raw":
        if cert_path is None:
            raise UsageError("usage_error", "raw mode requires --cert (the signer certificate)")
        certs = load_certificates(cert_path, "certificado do signatario")
        cert = certs[0]
        chain = list(certs[1:])
        for extra in chain_paths or []:
            chain.extend(load_certificates(extra, "certificado da cadeia"))
        if _fingerprint(cert) != announced:
            raise InputRejected("certificate_mismatch", "o certificado enviado e diferente do anunciado na preparacao")
        chain = [c for c in chain if _fingerprint(c) != announced]
        signature = _load_raw_signature(signature_path)
        mechanism = asn1_algos.SignedDigestAlgorithm({"algorithm": state["signature_mechanism"]}) \
            if state["signature_mechanism"] != "rsassa_pss" else _mechanism(cert, True)
        if not verify_signature(cert, mechanism.signature_algo, MD_ALGORITHM, signed_attrs_der, signature):
            raise InputRejected("signature_invalid", "a assinatura nao confere com o resumo preparado e o certificado anunciado")
        signing_cert = _asn1(cert)
        ext_signer = signers.ExternalSigner(
            signing_cert=signing_cert,
            cert_registry=SimpleCertificateStore.from_certs([signing_cert, *[_asn1(c) for c in chain]]),
            signature_value=signature,
            signature_mechanism=mechanism,
            prefer_pss=bool(state.get("prefer_pss")),
        )
        try:
            cms_object = asyncio.run(ext_signer.async_sign_prescribed_attributes(
                MD_ALGORITHM, signed_attrs=asn1_cms.CMSAttributes.load(signed_attrs_der)
            ))
        except (SigningError, ValueError, TypeError) as exc:
            raise ProcessingError("signing_failed", f"could not assemble the CMS: {type(exc).__name__}") from exc
        cms_der = cms_object.dump()
    else:
        info = _load_cms(cms_path)
        cert = _check_cms(info, state)
        if cert_path is not None and _fingerprint(load_certificates(cert_path)[0]) != announced:
            raise InputRejected("certificate_mismatch", "o certificado enviado e diferente do anunciado na preparacao")
        cms_der = info.dump()

    if len(cms_der) * 2 > end - start - 2:
        raise InputRejected("cms_too_large", "o CMS nao cabe no espaco reservado na preparacao")

    out_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        shutil.copyfile(pending_path, out_path)
        with out_path.open("r+b") as handle:
            PreparedByteRangeDigest(bytes.fromhex(state["document_digest_hex"]), start, end).fill_with_cms(handle, cms_der)
    except SigningError as exc:
        out_path.unlink(missing_ok=True)
        raise InputRejected("cms_too_large", f"o CMS nao cabe no espaco reservado: {exc}") from exc
    except OSError as exc:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("pdf_write_failed", f"could not write output: {exc}") from exc

    produced = out_path.read_bytes()
    base_ok = len(produced) == len(pending) and hashlib.sha256(produced[:base_size]).hexdigest() == state["base_sha256"]
    if not base_ok:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("revision_chain_broken", "the base revision was not preserved byte by byte")

    validation = validate_pdf(out_path, trust_paths=trust_paths or None)
    chain_analysis = analyse_chain(validation)
    expected = int(state["previous_signature_count"]) + 1
    if validation["signature_count"] != expected:
        chain_analysis["ok"] = False
        chain_analysis["problems"].append(f"expected {expected} signatures, found {validation['signature_count']}")
    newest = validation["signatures"][-1] if validation["signatures"] else {}
    if newest.get("field_name") != state["field_name"] or newest.get("cert_fingerprint_sha256") != announced:
        chain_analysis["ok"] = False
        chain_analysis["problems"].append("the newest signature is not the one just embedded")
    if not (newest.get("intact") and newest.get("valid")):
        out_path.unlink(missing_ok=True)
        raise InputRejected("signature_invalid", "a assinatura embutida nao valida no arquivo")
    if not chain_analysis["ok"]:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("revision_chain_broken", "; ".join(chain_analysis["problems"]) or "signature chain is not sound")

    return {
        "ok": True,
        "profile": PROFILE,
        "mode": mode,
        "field_name": state["field_name"],
        **{key: value for key, value in cert_summary(cert).items()},
        "signer_subject": cert.subject.rfc4514_string(),
        "certificate": describe_certificate(cert, []),
        "md_algorithm": MD_ALGORITHM,
        "timestamp": None,
        "previous_signature_count": int(state["previous_signature_count"]),
        "signature_count": validation["signature_count"],
        "base_size": base_size,
        "output_size": len(produced),
        "output_sha256": hashlib.sha256(produced).hexdigest(),
        "prefix_preserved": True,
        "trusted": bool(newest.get("trusted")),
        "revocation": REVOCATION_STATUS,
        "chain": chain_analysis,
        "validation": validation,
    }
