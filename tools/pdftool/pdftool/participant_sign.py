"""``participant-sign``: one PAdES B-B signature with the PARTICIPANT's own A1, incremental.

What it guarantees, in this order:

1. the PKCS#12 passes every ``inspect-cert`` check (passphrase from a NAMED environment
   variable, never argv; expired / not-yet-valid / keyless / non-signing certificates are
   refused before anything is written);
2. the requested field name is unique in the document (one field per participant); an
   existing field with that name — signed or empty — is refused (``field_name_taken``);
3. every signature already in the input is intact and valid BEFORE signing
   (``previous_signature_invalid``): a participant never signs on top of a broken chain;
4. the new signature is an *approval* signature (never a certification signature, so later
   revisions stay permitted) written as an incremental update: the input bytes are a strict
   prefix of the output (``prefix_preserved``), checked byte by byte;
5. the whole output is validated again and ALL signatures are returned — the previous
   participants', the operator's if any, and the new one — with the chain analysis:
   every signature intact and valid, the newest one covering the entire file, the older ones
   covering their revision with only permitted later changes (``NONE``/``FORM_FILLING``),
   no suspicious modification, no DocMDP violation. A broken chain deletes the output and
   fails with ``revision_chain_broken`` (exit 3).

The profile is PAdES-B-B: no time-stamp, no LTV, no revocation check (offline tool).
"""

from __future__ import annotations

import logging
import re
from pathlib import Path
from typing import Any, Dict, List, Optional

from asn1crypto import keys as asn1_keys
from asn1crypto import x509 as asn1_x509
from cryptography.hazmat.primitives import serialization
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.misc import PdfError
from pyhanko.sign import fields, signers
from pyhanko.sign.general import SigningError
from pyhanko_certvalidator.registry import SimpleCertificateStore

from pdftool.certs import cert_summary, read_passphrase
from pdftool.compose import reject_same_path
from pdftool.errors import InputRejected, ProcessingError, UsageError
from pdftool.inspect_cert import LoadedCertificate, describe_loaded, load_participant_pkcs12
from pdftool.inspect_cmd import close_reader, open_reader
from pdftool.validate import validate_pdf

log = logging.getLogger(__name__)

PROFILE = "PAdES-B-B"
FIELD_NAME_RE = re.compile(r"^[A-Za-z0-9_.-]{1,100}$")
# Changes a later incremental revision may make over an earlier signed one: adding a
# signature field and filling it is FORM_FILLING in pyHanko's difference analysis.
PERMITTED_LATER_CHANGES = {"NONE", "FORM_FILLING"}
_TRUST_ONLY_ERRORS = ("trust:", "self_anchored_chain_problem")


def analyse_chain(validation: Dict[str, Any]) -> Dict[str, Any]:
    """Is the sequence of incremental signatures sound as a whole?

    Trust (chain up to a configured root) is reported separately and is NOT a chain
    problem: without trust roots every signature is untrusted by construction.
    """
    signatures: List[Dict[str, Any]] = list(validation.get("signatures") or [])
    problems: List[str] = []
    if not signatures:
        problems.append("no_signatures")
    last_index = len(signatures) - 1
    for index, sig in enumerate(signatures):
        name = sig.get("field_name") or f"#{index + 1}"
        if not sig.get("intact"):
            problems.append(f"{name}: not_intact")
        if not sig.get("valid"):
            problems.append(f"{name}: not_valid")
        if sig.get("docmdp_ok") is False:
            problems.append(f"{name}: docmdp_violation")
        if index == last_index:
            if sig.get("coverage") != "ENTIRE_FILE":
                problems.append(f"{name}: newest_signature_does_not_cover_entire_file")
        else:
            if sig.get("coverage") not in ("ENTIRE_REVISION", "ENTIRE_FILE"):
                problems.append(f"{name}: unclear_coverage")
            if sig.get("modification_level") not in PERMITTED_LATER_CHANGES:
                problems.append(f"{name}: later_changes_not_permitted ({sig.get('modification_level')})")
        for error in sig.get("errors") or []:
            if not str(error).startswith(_TRUST_ONLY_ERRORS) and "digest_mismatch" not in str(error) \
                    and "invalid_signature" not in str(error):
                problems.append(f"{name}: {error}")
    return {
        "ok": not problems,
        "signature_count": len(signatures),
        "newest_covers_entire_file": bool(signatures) and signatures[-1].get("coverage") == "ENTIRE_FILE",
        "earlier_changes_permitted": all(
            s.get("modification_level") in PERMITTED_LATER_CHANGES for s in signatures[:-1]
        ),
        "problems": problems,
    }


def _existing_field_names(path: Path) -> List[str]:
    with path.open("rb") as handle:
        try:
            writer = IncrementalPdfFileWriter(handle, strict=False)
        except Exception as exc:  # noqa: BLE001 - pyHanko's parser is stricter than pypdf's
            raise InputRejected("invalid_pdf", f"pyHanko cannot parse PDF: {type(exc).__name__}") from exc
        try:
            return [str(name) for name, _value, _ref in fields.enumerate_sig_fields(writer.prev)]
        except Exception:  # noqa: BLE001 - no AcroForm at all
            return []


def _signer_from(loaded: LoadedCertificate) -> signers.SimpleSigner:
    signing_cert = asn1_x509.Certificate.load(loaded.cert.public_bytes(serialization.Encoding.DER))
    key_der = loaded.key.private_bytes(
        serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()
    )
    try:
        signing_key = asn1_keys.PrivateKeyInfo.load(key_der)
    finally:
        del key_der
    chain = [asn1_x509.Certificate.load(c.public_bytes(serialization.Encoding.DER)) for c in loaded.chain]
    return signers.SimpleSigner(
        signing_cert=signing_cert,
        signing_key=signing_key,
        cert_registry=SimpleCertificateStore.from_certs([signing_cert, *chain]),
    )


def participant_sign(
    in_path: Path,
    out_path: Path,
    pfx_path: Path,
    pass_env: str,
    field_name: str,
    reason: Optional[str] = None,
    location: Optional[str] = None,
    trust_paths: Optional[List[Path]] = None,
    expect_fingerprint: Optional[str] = None,
) -> Dict[str, Any]:
    passphrase = read_passphrase(pass_env)
    if not FIELD_NAME_RE.match(field_name or ""):
        raise UsageError("invalid_field_name", "--field-name must match [A-Za-z0-9_.-]{1,100}")
    reject_same_path(in_path, out_path)

    try:
        loaded = load_participant_pkcs12(pfx_path, passphrase)
    finally:
        del passphrase

    certificate = describe_loaded(loaded)
    if expect_fingerprint and certificate["cert_fingerprint_sha256"] != expect_fingerprint.strip().lower():
        raise UsageError("fingerprint_mismatch", "the certificate in the file is not the one that was inspected and consented to")

    # Pre-flight with pypdf: rejects corrupt/encrypted input.
    reader, info = open_reader(in_path)
    try:
        if info["encrypted"]:
            raise InputRejected("encrypted_pdf", "signing encrypted PDFs is not supported")
    finally:
        close_reader(reader)

    if field_name in _existing_field_names(in_path):
        raise UsageError("field_name_taken", f"signature field {field_name!r} already exists in the document")

    before = validate_pdf(in_path, trust_paths=trust_paths or None)
    for sig in before["signatures"]:
        if not (sig["intact"] and sig["valid"]):
            raise InputRejected(
                "previous_signature_invalid",
                f"signature {sig['field_name']!r} already in the document is not intact/valid; refusing to sign on top of it",
            )
    if before["signature_count"] and not analyse_chain(before)["ok"]:
        raise InputRejected("previous_signature_invalid", "the signatures already in the document do not form a sound chain")

    signer = _signer_from(loaded)
    meta = signers.PdfSignatureMetadata(
        field_name=field_name,
        md_algorithm="sha256",
        subfilter=fields.SigSeedSubFilter.PADES,
        certify=False,  # approval signature: later participants and the operator can still sign
        use_pades_lta=False,
        embed_validation_info=False,
        reason=reason or None,
        location=location or None,
    )

    original = in_path.read_bytes()
    out_path.parent.mkdir(parents=True, exist_ok=True)
    with in_path.open("rb") as inf:
        try:
            writer = IncrementalPdfFileWriter(inf, strict=False)
        except Exception as exc:  # noqa: BLE001
            raise InputRejected("invalid_pdf", f"pyHanko cannot parse PDF: {type(exc).__name__}") from exc
        pdf_signer = signers.PdfSigner(meta, signer, new_field_spec=fields.SigFieldSpec(sig_field_name=field_name))
        try:
            with out_path.open("wb") as outf:
                pdf_signer.sign_pdf(writer, output=outf)
        except SigningError as exc:
            out_path.unlink(missing_ok=True)
            raise ProcessingError("signing_failed", f"pyHanko refused to sign: {exc}") from exc
        except (PdfError, ValueError, TypeError, KeyError) as exc:
            out_path.unlink(missing_ok=True)
            raise ProcessingError("signing_failed", f"signing failed: {type(exc).__name__}: {exc}") from exc
        except OSError as exc:
            out_path.unlink(missing_ok=True)
            raise ProcessingError("pdf_write_failed", f"could not write output: {exc}") from exc
    del signer

    produced = out_path.read_bytes()
    prefix_preserved = len(produced) > len(original) and produced[: len(original)] == original
    if not prefix_preserved:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("revision_chain_broken", "the previous revision was not preserved byte by byte")

    validation = validate_pdf(out_path, trust_paths=trust_paths or None)
    chain = analyse_chain(validation)
    expected = before["signature_count"] + 1
    if validation["signature_count"] != expected:
        chain["ok"] = False
        chain["problems"].append(f"expected {expected} signatures, found {validation['signature_count']}")
    newest = validation["signatures"][-1] if validation["signatures"] else {}
    if newest.get("field_name") != field_name or newest.get("cert_fingerprint_sha256") != certificate["cert_fingerprint_sha256"]:
        chain["ok"] = False
        chain["problems"].append("the newest signature is not the one just applied")
    if not chain["ok"]:
        out_path.unlink(missing_ok=True)
        raise ProcessingError("revision_chain_broken", "; ".join(chain["problems"]) or "signature chain is not sound")

    summary = cert_summary(loaded.cert)
    return {
        "ok": True,
        "profile": PROFILE,
        "field_name": field_name,
        "signer_subject": summary["subject"],
        "issuer": summary["issuer"],
        "serial_hex": summary["serial_hex"],
        "cert_fingerprint_sha256": summary["cert_fingerprint_sha256"],
        "not_before": summary["not_before"],
        "not_after": summary["not_after"],
        "certificate": certificate,
        "md_algorithm": "sha256",
        "timestamp": None,
        "previous_signature_count": before["signature_count"],
        "signature_count": validation["signature_count"],
        "input_size": len(original),
        "output_size": len(produced),
        "prefix_preserved": True,
        "chain": chain,
        "validation": validation,
    }
