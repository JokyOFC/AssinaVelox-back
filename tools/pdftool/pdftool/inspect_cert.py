"""``inspect-cert`` and ``gen-test-participant-cert``: the participant's own A1 (PKCS#12).

``inspect-cert`` opens a PKCS#12 with the passphrase taken from a NAMED environment
variable (never argv), runs every check that must pass before the certificate may sign
anything, and returns only public facts. No key material, no passphrase, no PFX bytes
ever reach stdout, stderr or an error message.

Refused (exit 4, stable ``error.code``):

- ``missing_input``                 file not found
- ``pkcs12_too_large``              file larger than MAX_PFX_BYTES
- ``invalid_pkcs12``                not a readable PKCS#12 structure (corrupt file)
- ``wrong_passphrase``              structure is valid, but the passphrase does not open it
- ``pkcs12_without_certificate``    container has no end-entity certificate
- ``pkcs12_without_key``            container has no private key
- ``key_certificate_mismatch``      the key does not belong to the certificate
- ``certificate_is_ca``             the end-entity certificate is a CA certificate
- ``certificate_not_yet_valid``     notBefore is in the future
- ``certificate_expired``           notAfter is in the past
- ``certificate_not_for_signing``   keyUsage/extendedKeyUsage do not allow signing

Where the CPF lives in an ICP-Brasil certificate (read, never confirmed against the
Receita Federal):

- ``subjectAltName`` ``otherName`` with OID ``2.16.76.1.3.1`` ("dados do titular" of an
  e-CPF): 8 digits birth date (DDMMAAAA) followed by the 11-digit CPF, then NIS, RG and
  issuing body. Layout taken from DOC-ICP-04 as understood by this project; the exact
  ASN.1 string type varies between ACs (OCTET STRING, PrintableString, UTF8String) and
  all are accepted. NOT CONFIRMED against the current DOC-ICP-04 text in this wave.
- the e-CPF Common Name convention ``NOME DO TITULAR:CPF`` (fallback only).

``cpf_confirmed`` is always ``false``: the tool reads what the certificate says and checks
the CPF check digits; it does not consult any registry.
"""

from __future__ import annotations

import datetime as dt
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional

from asn1crypto import core as asn1_core
from asn1crypto import pkcs12 as asn1_pkcs12
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, rsa
from cryptography.hazmat.primitives.serialization import pkcs12
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID

from pdftool.certs import cert_summary, read_passphrase, serial_hex
from pdftool.errors import InputRejected, ProcessingError, UsageError

MAX_PFX_BYTES = 256 * 1024
TEST_MARKER = "TESTE"

# ICP-Brasil: arcs used by the certificate policies and by the holder data (DOC-ICP-04).
ICP_BRASIL_POLICY_ARC = "2.16.76.1.2."
ICP_CPF_HOLDER_OID = "2.16.76.1.3.1"   # e-CPF: birth date (8) + CPF (11) + NIS (11) + RG (15) + issuer/UF
ICP_CNPJ_OID = "2.16.76.1.3.3"         # e-CNPJ: CNPJ of the company
ICP_RESPONSIBLE_OID = "2.16.76.1.3.4"  # e-CNPJ: responsible person (birth date + CPF + ...)

# Extended key usages compatible with document signing. ICP-Brasil e-CPF/e-CNPJ certificates
# usually carry clientAuth + emailProtection; the others are the document-signing EKUs in use.
SIGNING_EKUS = {
    ExtendedKeyUsageOID.ANY_EXTENDED_KEY_USAGE.dotted_string: "any_extended_key_usage",
    ExtendedKeyUsageOID.CLIENT_AUTH.dotted_string: "client_auth",
    ExtendedKeyUsageOID.EMAIL_PROTECTION.dotted_string: "email_protection",
    "1.3.6.1.5.5.7.3.36": "document_signing",           # id-kp-documentSigning (RFC 9336)
    "1.3.6.1.4.1.311.10.3.12": "microsoft_document_signing",
    "1.2.840.113583.1.1.5": "adobe_authentic_documents",
}
OTHER_EKUS = {
    ExtendedKeyUsageOID.SERVER_AUTH.dotted_string: "server_auth",
    ExtendedKeyUsageOID.CODE_SIGNING.dotted_string: "code_signing",
    ExtendedKeyUsageOID.TIME_STAMPING.dotted_string: "time_stamping",
    ExtendedKeyUsageOID.OCSP_SIGNING.dotted_string: "ocsp_signing",
}

_CN_CPF_RE = re.compile(r"^(?P<name>.+?)\s*:\s*(?P<cpf>\d{11})$")


def _iso(value: dt.datetime) -> str:
    if value.tzinfo is None:
        value = value.replace(tzinfo=dt.timezone.utc)
    return value.astimezone(dt.timezone.utc).isoformat(timespec="seconds")


def _now() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


def cpf_check_digits_valid(cpf: str) -> bool:
    """Standard CPF check-digit algorithm (11 digits, not all equal)."""
    if not re.fullmatch(r"\d{11}", cpf or "") or len(set(cpf)) == 1:
        return False
    digits = [int(c) for c in cpf]
    for size in (9, 10):
        total = sum(digits[i] * (size + 1 - i) for i in range(size))
        check = (total * 10) % 11
        if check == 10:
            check = 0
        if check != digits[size]:
            return False
    return True


def mask_cpf(cpf: Optional[str]) -> Optional[str]:
    """``12345678909`` -> ``***.456.789-**`` (same mask used by the Laravel side)."""
    if not cpf or len(cpf) != 11:
        return None
    return f"***.{cpf[3:6]}.{cpf[6:9]}-**"


def _common_name(name: x509.Name) -> Optional[str]:
    values = name.get_attributes_for_oid(NameOID.COMMON_NAME)
    return str(values[0].value) if values else None


def _other_name_text(value: bytes) -> Optional[str]:
    """Decode the DER value of an ``otherName`` as text (OCTET STRING or any string type)."""
    try:
        parsed = asn1_core.Asn1Value.load(value)
    except Exception:  # noqa: BLE001 - malformed extension: treated as absent
        return None
    contents = parsed.contents if parsed.contents is not None else b""
    try:
        text = contents.decode("latin-1")
    except Exception:  # noqa: BLE001
        return None
    return text


def holder_facts(cert: x509.Certificate) -> Dict[str, Any]:
    """Holder name and CPF as the certificate states them (never confirmed)."""
    cn = _common_name(cert.subject)
    name = cn
    cpf: Optional[str] = None
    source: Optional[str] = None
    cnpj: Optional[str] = None

    try:
        san = cert.extensions.get_extension_for_class(x509.SubjectAlternativeName).value
        for other in san.get_values_for_type(x509.OtherName):
            oid = other.type_id.dotted_string
            text = _other_name_text(other.value) or ""
            if oid == ICP_CPF_HOLDER_OID and cpf is None:
                candidate = text[8:19]
                if re.fullmatch(r"\d{11}", candidate) and candidate != "0" * 11:
                    cpf, source = candidate, f"subject_alt_name.other_name.{ICP_CPF_HOLDER_OID}"
            elif oid == ICP_CNPJ_OID and cnpj is None:
                candidate = re.sub(r"\D", "", text)[:14]
                if len(candidate) == 14:
                    cnpj = candidate
    except x509.ExtensionNotFound:
        pass

    if cn:
        match = _CN_CPF_RE.match(cn.replace(f" {TEST_MARKER}", "").strip()) or _CN_CPF_RE.match(cn)
        if match:
            name = match.group("name").strip()
            if cpf is None:
                cpf, source = match.group("cpf"), "subject.common_name_suffix"

    return {
        "name": name,
        "cpf": cpf,
        "cpf_masked": mask_cpf(cpf),
        "cpf_source": source,
        "cpf_check_digits_valid": cpf_check_digits_valid(cpf) if cpf else None,
        # Nothing here consults the Receita Federal: the CPF is what the certificate says.
        "cpf_confirmed": False,
        "cnpj": cnpj,
    }


def _key_usage(cert: x509.Certificate) -> Optional[List[str]]:
    try:
        ku = cert.extensions.get_extension_for_class(x509.KeyUsage).value
    except x509.ExtensionNotFound:
        return None
    names = []
    for attr in ("digital_signature", "content_commitment", "key_encipherment", "data_encipherment",
                 "key_agreement", "key_cert_sign", "crl_sign"):
        if getattr(ku, attr):
            names.append("non_repudiation" if attr == "content_commitment" else attr)
    return names


def _extended_key_usage(cert: x509.Certificate) -> Optional[List[Dict[str, str]]]:
    try:
        eku = cert.extensions.get_extension_for_class(x509.ExtendedKeyUsage).value
    except x509.ExtensionNotFound:
        return None
    out = []
    for oid in eku:
        dotted = oid.dotted_string
        out.append({"oid": dotted, "name": SIGNING_EKUS.get(dotted) or OTHER_EKUS.get(dotted) or "other"})
    return out


def _policy_oids(cert: x509.Certificate) -> List[str]:
    try:
        policies = cert.extensions.get_extension_for_class(x509.CertificatePolicies).value
    except x509.ExtensionNotFound:
        return []
    return [p.policy_identifier.dotted_string for p in policies]


def _is_ca(cert: x509.Certificate) -> bool:
    try:
        return bool(cert.extensions.get_extension_for_class(x509.BasicConstraints).value.ca)
    except x509.ExtensionNotFound:
        return False


def _key_description(key) -> Dict[str, Any]:
    if isinstance(key, rsa.RSAPublicKey):
        return {"key_algorithm": "RSA", "key_size": key.key_size}
    if isinstance(key, ec.EllipticCurvePublicKey):
        return {"key_algorithm": f"EC-{key.curve.name}", "key_size": key.key_size}
    return {"key_algorithm": type(key).__name__, "key_size": getattr(key, "key_size", None)}


def _public_bytes(key) -> bytes:
    return key.public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)


def _is_test_certificate(cert: x509.Certificate) -> bool:
    subject_cn = (_common_name(cert.subject) or "").upper()
    issuer_cn = (_common_name(cert.issuer) or "").upper()
    return TEST_MARKER in subject_cn or TEST_MARKER in issuer_cn


@dataclass
class LoadedCertificate:
    """A PKCS#12 that passed every check. Holds live key objects: never serialise it."""

    key: Any
    cert: x509.Certificate
    chain: List[x509.Certificate] = field(default_factory=list)

    def __repr__(self) -> str:  # never print key material by accident
        return f"LoadedCertificate(subject={self.cert.subject.rfc4514_string()!r})"


def load_participant_pkcs12(pfx: Path, passphrase: str, at: Optional[dt.datetime] = None) -> LoadedCertificate:
    """Open and check a participant PKCS#12. Raises ``InputRejected`` with a stable code."""
    if not pfx.is_file():
        raise InputRejected("missing_input", "arquivo do certificado nao encontrado")
    try:
        size = pfx.stat().st_size
        if size > MAX_PFX_BYTES:
            raise InputRejected("pkcs12_too_large", f"o arquivo do certificado passa de {MAX_PFX_BYTES // 1024} KB")
        data = pfx.read_bytes()
    except OSError as exc:
        raise ProcessingError("read_failed", f"nao foi possivel ler o arquivo do certificado: {type(exc).__name__}") from exc

    # Structure first: tells a corrupt file apart from a wrong passphrase.
    try:
        parsed = asn1_pkcs12.Pfx.load(data)
        parsed["auth_safe"]["content_type"].native  # forces parsing of the outer structure
        if parsed["version"].native != "v3":
            raise ValueError("unsupported PFX version")
    except Exception as exc:  # noqa: BLE001
        raise InputRejected("invalid_pkcs12", "o arquivo nao e um certificado PKCS#12 (.pfx/.p12) legivel") from exc

    try:
        bundle = pkcs12.load_pkcs12(data, passphrase.encode("utf-8"))
    except Exception as exc:  # noqa: BLE001 - message never carries the passphrase
        raise InputRejected(
            "wrong_passphrase", "a senha nao abre este certificado (senha incorreta ou protecao nao suportada)"
        ) from exc
    finally:
        del data

    # Without a private key there is no localKeyId pairing, and the library reports the
    # certificate among `additional_certs` instead of `cert`: check the key first.
    if bundle.key is None:
        if bundle.cert is not None or bundle.additional_certs:
            raise InputRejected(
                "pkcs12_without_key", "o arquivo contem o certificado, mas nao a chave privada; exporte o .pfx com a chave"
            )
        raise InputRejected("pkcs12_without_certificate", "o arquivo nao contem certificado nem chave privada")
    if bundle.cert is None:
        raise InputRejected("pkcs12_without_certificate", "o arquivo nao contem o certificado do titular")

    cert = bundle.cert.certificate
    chain = [c.certificate for c in (bundle.additional_certs or [])]

    if _public_bytes(bundle.key.public_key()) != _public_bytes(cert.public_key()):
        raise InputRejected("key_certificate_mismatch", "a chave privada do arquivo nao corresponde ao certificado")
    if _is_ca(cert):
        raise InputRejected("certificate_is_ca", "o certificado e de uma autoridade certificadora, nao de um titular")

    now = at or _now()
    if cert.not_valid_before_utc > now:
        raise InputRejected("certificate_not_yet_valid", f"o certificado so passa a valer em {_iso(cert.not_valid_before_utc)}")
    if cert.not_valid_after_utc < now:
        raise InputRejected("certificate_expired", f"o certificado venceu em {_iso(cert.not_valid_after_utc)}")

    key_usage = _key_usage(cert)
    if key_usage is not None and not ({"digital_signature", "non_repudiation"} & set(key_usage)):
        raise InputRejected("certificate_not_for_signing", "o uso de chave do certificado nao permite assinatura digital")
    eku = _extended_key_usage(cert)
    if eku is not None and not any(item["oid"] in SIGNING_EKUS for item in eku):
        raise InputRejected("certificate_not_for_signing", "o uso estendido do certificado nao permite assinar documentos")

    return LoadedCertificate(key=bundle.key, cert=cert, chain=chain)


def describe_loaded(loaded: LoadedCertificate) -> Dict[str, Any]:
    """Public facts only. Safe to print and to store."""
    cert = loaded.cert
    summary = cert_summary(cert)
    self_signed = cert.subject == cert.issuer
    now = _now()
    chain = []
    for extra in loaded.chain:
        item = cert_summary(extra)
        item.update({"self_signed": extra.subject == extra.issuer, "is_ca": _is_ca(extra)})
        chain.append(item)
    reaches_root = self_signed or any(c["self_signed"] for c in chain)
    policies = _policy_oids(cert)
    declares_icp = any(oid.startswith(ICP_BRASIL_POLICY_ARC) for oid in policies)
    key_usage = _key_usage(cert)
    eku = _extended_key_usage(cert)
    test = _is_test_certificate(cert)

    result: Dict[str, Any] = {
        "ok": True,
        **summary,
        "subject_cn": _common_name(cert.subject),
        "issuer_cn": _common_name(cert.issuer),
        "days_remaining": max(0, (cert.not_valid_after_utc - now).days),
        **_key_description(cert.public_key()),
        "key_usage": key_usage,
        "extended_key_usage": eku,
        "has_private_key": True,
        "key_matches_certificate": True,
        "self_signed": self_signed,
        "is_ca": False,
        "chain": chain,
        "chain_length": len(chain),
        "chain_reaches_self_signed_root": reaches_root,
        "holder": holder_facts(cert),
        "icp_brasil": {
            "policy_oids": policies,
            # A policy OID under 2.16.76.1.2 is a CLAIM made by the certificate itself.
            # Only a chain validated up to a pinned ICP-Brasil root would make it a fact.
            "declares_icp_brasil_policy": declares_icp,
            "chain_validated": False,
            "note": "cadeia nao validada ate uma raiz ICP-Brasil por esta ferramenta",
        },
        "test_certificate": test,
        "warnings": [],
    }
    if test:
        result["warnings"].append("certificado de TESTE: nao e ICP-Brasil e nao tem validade juridica")
    if self_signed and not test:
        result["warnings"].append("certificado autoassinado: nenhuma autoridade certificadora atesta o titular")
    if not reaches_root:
        result["warnings"].append("a cadeia de certificacao nao esta completa dentro do arquivo")
    return result


def inspect_certificate(pfx: Path, pass_env: str) -> Dict[str, Any]:
    passphrase = read_passphrase(pass_env)
    try:
        loaded = load_participant_pkcs12(pfx, passphrase)
    finally:
        del passphrase
    return describe_loaded(loaded)


# --------------------------------------------------------------------------------------
# gen-test-participant-cert: TEST fixtures for automated tests (never ICP-Brasil)
# --------------------------------------------------------------------------------------

def _mark_test(common_name: str) -> str:
    if TEST_MARKER in common_name.upper():
        return common_name
    match = _CN_CPF_RE.match(common_name)
    if match:
        return f"{match.group('name')} {TEST_MARKER}:{match.group('cpf')}"
    return f"{common_name} {TEST_MARKER}"


def generate_participant_test_cert(
    out_pfx: Path,
    pass_env: str,
    common_name: str = "Participante",
    cpf: Optional[str] = None,
    days: int = 30,
    valid_from_days: int = 0,
    key_usage: str = "signing",
    no_key: bool = False,
    self_signed: bool = False,
    out_ca_pem: Optional[Path] = None,
) -> Dict[str, Any]:
    """TEST certificate for a participant, issued by a throw-away TEST CA (or self-signed).

    ``valid_from_days`` shifts notBefore (negative = in the past), which lets tests build
    expired (``--valid-from-days -60 --days 30``) and not-yet-valid certificates.
    ``--no-key`` writes a PKCS#12 with the certificate only. ``--key-usage encipherment``
    writes a certificate that cannot sign. The CN always carries "TESTE".
    """
    passphrase = read_passphrase(pass_env)
    if not 1 <= days <= 3650:
        raise UsageError("invalid_days", "--days must be between 1 and 3650")
    if not -3650 <= valid_from_days <= 3650:
        raise UsageError("invalid_days", "--valid-from-days must be between -3650 and 3650")
    if key_usage not in ("signing", "encipherment"):
        raise UsageError("usage_error", "--key-usage must be signing or encipherment")
    if cpf is not None and not re.fullmatch(r"\d{11}", cpf):
        raise UsageError("usage_error", "--cpf must have 11 digits")

    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
    not_before = now + dt.timedelta(days=valid_from_days) - dt.timedelta(minutes=5)
    not_after = not_before + dt.timedelta(days=days)

    ca_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    ca_name = x509.Name([
        x509.NameAttribute(NameOID.COMMON_NAME, f"AC {TEST_MARKER} AssinaVelox Participantes"),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, "AssinaVelox TESTE"),
        x509.NameAttribute(NameOID.COUNTRY_NAME, "BR"),
    ])
    ca_ski = x509.SubjectKeyIdentifier.from_public_key(ca_key.public_key())
    ca_cert = (
        x509.CertificateBuilder()
        .subject_name(ca_name).issuer_name(ca_name).public_key(ca_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - dt.timedelta(days=3650)).not_valid_after(now + dt.timedelta(days=3650))
        .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
        .add_extension(x509.KeyUsage(digital_signature=False, content_commitment=False, key_encipherment=False,
                                     data_encipherment=False, key_agreement=False, key_cert_sign=True,
                                     crl_sign=True, encipher_only=False, decipher_only=False), critical=True)
        .add_extension(ca_ski, critical=False)
        .sign(ca_key, hashes.SHA256())
    )

    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([
        x509.NameAttribute(NameOID.COMMON_NAME, _mark_test(common_name if not cpf else f"{common_name}:{cpf}")),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, "AssinaVelox TESTE"),
        x509.NameAttribute(NameOID.COUNTRY_NAME, "BR"),
    ])
    issuer_name, signing_key = (subject, key) if self_signed else (ca_name, ca_key)
    signing = key_usage == "signing"
    ski = x509.SubjectKeyIdentifier.from_public_key(key.public_key())
    builder = (
        x509.CertificateBuilder()
        .subject_name(subject).issuer_name(issuer_name).public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(not_before).not_valid_after(not_after)
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(x509.KeyUsage(digital_signature=signing, content_commitment=signing,
                                     key_encipherment=not signing, data_encipherment=False, key_agreement=False,
                                     key_cert_sign=False, crl_sign=False, encipher_only=False, decipher_only=False),
                       critical=True)
        .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.CLIENT_AUTH, ExtendedKeyUsageOID.EMAIL_PROTECTION]),
                       critical=False)
        .add_extension(ski, critical=False)
        .add_extension(
            x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(ski if self_signed else ca_ski),
            critical=False,
        )
    )
    if cpf:
        holder = "01011990" + cpf + "0" * 11 + "0" * 15 + "SSPSP "
        builder = builder.add_extension(
            x509.SubjectAlternativeName([
                x509.OtherName(x509.ObjectIdentifier(ICP_CPF_HOLDER_OID), asn1_core.OctetString(holder.encode("ascii")).dump()),
                x509.RFC822Name("participante.teste@example.test"),
            ]),
            critical=False,
        )
    cert = builder.sign(signing_key, hashes.SHA256())

    try:
        pfx_bytes = pkcs12.serialize_key_and_certificates(
            name=b"AssinaVelox TESTE participante",
            key=None if no_key else key,
            cert=cert,
            cas=None if self_signed else [ca_cert],
            encryption_algorithm=serialization.BestAvailableEncryption(passphrase.encode("utf-8")),
        )
        out_pfx.parent.mkdir(parents=True, exist_ok=True)
        out_pfx.write_bytes(pfx_bytes)
        if out_ca_pem is not None:
            out_ca_pem.parent.mkdir(parents=True, exist_ok=True)
            out_ca_pem.write_bytes((cert if self_signed else ca_cert).public_bytes(serialization.Encoding.PEM))
    except OSError as exc:
        raise ProcessingError("write_failed", f"could not write certificate files: {exc}") from exc
    finally:
        del passphrase

    result: Dict[str, Any] = {
        "ok": True,
        "pfx_path": str(out_pfx),
        "ca_pem_path": str(out_ca_pem) if out_ca_pem else None,
        **cert_summary(cert),
        "has_private_key": not no_key,
        "self_signed": self_signed,
        "cpf": cpf,
        "test_only": True,
        "warning": "Certificado de TESTE de participante. Nao e ICP-Brasil e nao tem validade juridica.",
    }
    result["serial_hex"] = serial_hex(cert.serial_number)
    return result
