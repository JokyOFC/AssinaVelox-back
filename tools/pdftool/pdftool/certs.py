"""Certificate helpers and ``gen-test-cert`` (self-signed test certificates).

Certificates produced here are for automated tests ONLY. They are
self-signed, carry "TESTE" in the Common Name and are NOT ICP-Brasil
certificates.
"""

from __future__ import annotations

import datetime as dt
import os
import re
from pathlib import Path
from typing import Any, Dict, Optional

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives.serialization import pkcs12
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID

from pdftool.errors import InputRejected, ProcessingError, UsageError

ENV_NAME_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
DEFAULT_SUBJECT = "CN=AssinaVelox TESTE,O=AssinaVelox,C=BR"
TEST_MARKER = "TESTE"
TEST_WARNING = (
    "Certificado autoassinado de TESTE. Nao e emitido pela ICP-Brasil e nao tem validade juridica."
)


def read_passphrase(env_var: str) -> str:
    """Read a passphrase from the named environment variable (never from argv)."""
    if not env_var or not ENV_NAME_RE.match(env_var):
        raise UsageError("invalid_env_name", "environment variable name must match [A-Za-z_][A-Za-z0-9_]*")
    value = os.environ.get(env_var)
    if value is None or value == "":
        raise UsageError("missing_passphrase", f"environment variable {env_var} is not set or empty")
    return value


def _iso(value: dt.datetime) -> str:
    if value.tzinfo is None:
        value = value.replace(tzinfo=dt.timezone.utc)
    return value.astimezone(dt.timezone.utc).isoformat(timespec="seconds")


def serial_hex(serial: int) -> str:
    text = format(serial, "x")
    return text if len(text) % 2 == 0 else "0" + text


def cert_summary(cert: x509.Certificate) -> Dict[str, Any]:
    """Public, non-secret facts about a certificate (RFC 4514 names, hex serial, ...)."""
    return {
        "subject": cert.subject.rfc4514_string(),
        "issuer": cert.issuer.rfc4514_string(),
        "serial_hex": serial_hex(cert.serial_number),
        "cert_fingerprint_sha256": cert.fingerprint(hashes.SHA256()).hex(),
        "not_before": _iso(cert.not_valid_before_utc),
        "not_after": _iso(cert.not_valid_after_utc),
    }


def cert_from_asn1(asn1_cert) -> x509.Certificate:
    """Convert an ``asn1crypto`` certificate (pyHanko's type) to ``cryptography``."""
    return x509.load_der_x509_certificate(asn1_cert.dump())


def _ensure_test_marker(name: x509.Name) -> x509.Name:
    """Guarantee the CN contains "TESTE" so test certs are never mistaken for real ones."""
    rdns = []
    found_cn = False
    for rdn in name.rdns:
        attrs = []
        for attr in rdn:
            if attr.oid == NameOID.COMMON_NAME:
                found_cn = True
                if TEST_MARKER not in str(attr.value).upper():
                    attr = x509.NameAttribute(NameOID.COMMON_NAME, f"{attr.value} {TEST_MARKER}")
            attrs.append(attr)
        rdns.append(x509.RelativeDistinguishedName(attrs))
    if not found_cn:
        rdns.insert(0, x509.RelativeDistinguishedName([x509.NameAttribute(NameOID.COMMON_NAME, f"AssinaVelox {TEST_MARKER}")]))
    return x509.Name(rdns)


def generate_test_cert(
    out_pfx: Path,
    pass_env: str,
    subject: str = DEFAULT_SUBJECT,
    days: int = 365,
    out_pem: Optional[Path] = None,
) -> Dict[str, Any]:
    passphrase = read_passphrase(pass_env)
    if days < 1 or days > 3650:
        raise UsageError("invalid_days", "--days must be between 1 and 3650")
    try:
        name = _ensure_test_marker(x509.Name.from_rfc4514_string(subject))
    except ValueError as exc:
        raise UsageError("invalid_subject", f"subject is not a valid RFC 4514 string: {exc}") from exc

    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
    ski = x509.SubjectKeyIdentifier.from_public_key(key.public_key())
    builder = (
        x509.CertificateBuilder()
        .subject_name(name)
        .issuer_name(name)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - dt.timedelta(minutes=5))
        .not_valid_after(now + dt.timedelta(days=days))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=True,  # a.k.a. nonRepudiation
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=False,
                crl_sign=False,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(
            x509.ExtendedKeyUsage([ExtendedKeyUsageOID.EMAIL_PROTECTION, ExtendedKeyUsageOID.CLIENT_AUTH]),
            critical=False,
        )
        .add_extension(ski, critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(ski), critical=False)
    )
    cert = builder.sign(key, hashes.SHA256())

    try:
        pfx_bytes = pkcs12.serialize_key_and_certificates(
            name=b"AssinaVelox TESTE",
            key=key,
            cert=cert,
            cas=None,
            encryption_algorithm=serialization.BestAvailableEncryption(passphrase.encode("utf-8")),
        )
        out_pfx.parent.mkdir(parents=True, exist_ok=True)
        out_pfx.write_bytes(pfx_bytes)
        if out_pem is not None:
            out_pem.parent.mkdir(parents=True, exist_ok=True)
            out_pem.write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    except OSError as exc:
        raise ProcessingError("write_failed", f"could not write certificate files: {exc}") from exc
    finally:
        del passphrase

    result: Dict[str, Any] = {"ok": True, "pfx_path": str(out_pfx), "pem_path": str(out_pem) if out_pem else None}
    result.update(cert_summary(cert))
    result.update({"key_algorithm": "RSA-2048", "self_signed": True, "test_only": True, "warning": TEST_WARNING})
    return result


def describe_pkcs12(pfx: Path, pass_env: str) -> Dict[str, Any]:
    """Public facts about the certificate inside a PKCS#12 file.

    Read-only: it opens the container with the passphrase taken from the named
    environment variable (never from argv) and returns only non-secret metadata
    — subject, issuer, serial, SHA-256 fingerprint and validity. No key
    material and no passphrase ever reaches the output or an error message.

    This is what lets the platform name the certificate that is ABOUT to sign,
    instead of guessing it from the most recent row of ``certificate_references``.
    """
    passphrase = read_passphrase(pass_env)
    if not pfx.is_file():
        raise InputRejected("missing_input", f"PKCS#12 file not found: {pfx}")
    try:
        key, cert, extra = pkcs12.load_key_and_certificates(pfx.read_bytes(), passphrase.encode("utf-8"))
    except OSError as exc:
        raise ProcessingError("read_failed", f"could not read the PKCS#12 file: {exc}") from exc
    except Exception as exc:  # noqa: BLE001 - wrong passphrase, corrupt container, unsupported algorithm
        raise InputRejected("invalid_pkcs12", f"cannot open the PKCS#12 container: {type(exc).__name__}") from exc
    finally:
        del passphrase

    if cert is None:
        raise InputRejected("invalid_pkcs12", "the PKCS#12 container has no end-entity certificate")

    result: Dict[str, Any] = {
        "ok": True,
        "pfx_path": str(pfx),
        "has_private_key": key is not None,
        "chain_length": len(extra or []),
    }
    result.update(cert_summary(cert))
    result["self_signed"] = cert.subject == cert.issuer
    return result
