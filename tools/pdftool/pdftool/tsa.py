"""RFC 3161 Time-Stamp Authority operated by the PLATFORM OPERATOR (``tsa_kind=operator``).

What this module is:

* ``tsa-issue``  — build and sign a ``TimeStampResp`` (RFC 3161 + RFC 5816) with the
  operator TSA key. The serial number is NEVER random: it is handed in by the caller
  (the Laravel side allocates it from a database sequence) or taken from a locked
  serial file (``--serial-file``, standalone/dev use).
* ``tsa-verify`` — check a token against a digest and (optionally) a trust anchor. The
  cryptographic part is delegated to pyHanko's ``validate_tst_signed_data`` so the check
  is independent of the issuing code in this file.
* ``tsa-gen-test`` — generate a TEST TSA (internal root + TSA certificate with a critical
  ``id-kp-timeStamping`` EKU). Never for production.

What this module is NOT: an ICP-Brasil time stamp. Only an ACT accredited by ITI issues
those (DOC-ICP-11 §2.7.2). A token from here proves "the AssinaVelox operator attests this
digest existed at genTime" — nothing more. Production additionally needs a protected key
(HSM/KMS), a monitored NTP clock, an own policy OID and a TSA certificate from the
operator's internal CA (docs/fase-2/carimbo-e-dossie.md, checklist).

Secrets: the PKCS#12 passphrase is read ONLY from the environment variable named by
``--pass-env`` (never argv) and never appears in stdout, stderr or error messages.
"""

from __future__ import annotations

import asyncio
import datetime as dt
import hashlib
import os
import re
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from asn1crypto import algos, cms, core, pem, tsp
from asn1crypto import x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, padding, rsa
from cryptography.hazmat.primitives.serialization import pkcs12
from cryptography.x509.oid import ExtendedKeyUsageOID, ExtensionOID, NameOID

from pdftool.certs import TEST_MARKER, cert_summary, read_passphrase
from pdftool.errors import InputRejected, ProcessingError, UsageError

TSA_KIND = "operator"
TSA_LABEL = "Carimbo do tempo da operadora — não é carimbo ICP-Brasil"
HASH_ALGORITHMS: Dict[str, int] = {"sha256": 32, "sha384": 48, "sha512": 64}
SIGNING_DIGEST = "sha256"
MAX_SERIAL = 2**159  # RFC 3161 serials are at most 160 bits (positive INTEGER)
MAX_REQUEST_BYTES = 64 * 1024
OID_RE = re.compile(r"^[0-2](\.(0|[1-9][0-9]*))+$")
DEFAULT_TSA_SUBJECT = "CN=AssinaVelox TSA TESTE,O=AssinaVelox,C=BR"
DEFAULT_ROOT_SUBJECT = "CN=AssinaVelox AC Interna TESTE,O=AssinaVelox,C=BR"
TEST_WARNING = (
    "TSA de TESTE: certificados autoassinados gerados localmente. Nao e ACT ICP-Brasil, "
    "nao tem validade juridica e nao pode ser usada em producao."
)


class TimeStampResponse(core.Sequence):
    """RFC 3161 ``TimeStampResp`` with ``timeStampToken`` OPTIONAL, as the RFC defines it.

    ``asn1crypto.tsp.TimeStampResp`` declares the token as mandatory, which makes a
    (perfectly valid) rejection response impossible to encode or parse.
    """

    _fields = [
        ("status", tsp.PKIStatusInfo),
        ("time_stamp_token", cms.ContentInfo, {"optional": True}),
    ]


class Rejection(Exception):
    """A request the TSA refuses with an RFC 3161 ``rejection`` response (not a crash)."""

    def __init__(self, fail_info: str, text: str):
        super().__init__(text)
        self.fail_info = fail_info
        self.text = text


@dataclass
class TsaCredentials:
    key: Any
    cert: asn1_x509.Certificate
    crypto_cert: x509.Certificate
    chain: List[asn1_x509.Certificate]

    @property
    def is_test(self) -> bool:
        cns = self.crypto_cert.subject.get_attributes_for_oid(NameOID.COMMON_NAME)
        return any(TEST_MARKER in str(attr.value).upper() for attr in cns)


# --------------------------------------------------------------------------- helpers


def _iso(value: Optional[dt.datetime]) -> Optional[str]:
    if value is None:
        return None
    if value.tzinfo is None:
        value = value.replace(tzinfo=dt.timezone.utc)
    return value.astimezone(dt.timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def _to_asn1(cert: x509.Certificate) -> asn1_x509.Certificate:
    return asn1_x509.Certificate.load(cert.public_bytes(serialization.Encoding.DER))


def _to_crypto(cert: asn1_x509.Certificate) -> x509.Certificate:
    return x509.load_der_x509_certificate(cert.dump())


def validate_policy_oid(oid: str) -> str:
    oid = (oid or "").strip()
    if not OID_RE.match(oid):
        raise UsageError("invalid_policy_oid", "--policy-oid must be a dotted OID such as 2.25.123")
    return oid


def validate_serial(serial: int) -> int:
    if serial < 1 or serial >= MAX_SERIAL:
        raise UsageError("invalid_serial", "serial must be a positive integer below 2^159")
    return serial


def parse_digest(hex_digest: str, algorithm: str) -> bytes:
    algorithm = (algorithm or "").lower()
    if algorithm not in HASH_ALGORITHMS:
        raise UsageError("unsupported_hash_algorithm", "hash algorithm must be sha256, sha384 or sha512")
    text = (hex_digest or "").strip().lower()
    if not re.fullmatch(r"[0-9a-f]+", text) or len(text) != HASH_ALGORITHMS[algorithm] * 2:
        raise UsageError("invalid_digest", f"digest must be {HASH_ALGORITHMS[algorithm] * 2} hexadecimal characters for {algorithm}")
    return bytes.fromhex(text)


def load_certificates(paths: List[Path], code: str = "invalid_trust_file") -> List[asn1_x509.Certificate]:
    certs: List[asn1_x509.Certificate] = []
    for path in paths:
        if not path.is_file():
            raise UsageError("trust_file_not_found", f"certificate file not found: {path.name}")
        data = path.read_bytes()
        try:
            if pem.detect(data):
                for _type, _headers, der in pem.unarmor(data, multiple=True):
                    certs.append(asn1_x509.Certificate.load(der))
            else:
                certs.append(asn1_x509.Certificate.load(data))
        except Exception as exc:  # noqa: BLE001
            raise UsageError(code, f"cannot parse certificate(s) in {path.name}: {type(exc).__name__}") from exc
    return certs


# --------------------------------------------------------------------------- credentials


def check_tsa_certificate(cert: x509.Certificate, at: Optional[dt.datetime] = None) -> None:
    """RFC 3161 §2.3: exactly one EKU, ``id-kp-timeStamping``, and it MUST be critical."""
    try:
        eku = cert.extensions.get_extension_for_oid(ExtensionOID.EXTENDED_KEY_USAGE)
    except x509.ExtensionNotFound:
        raise InputRejected("invalid_tsa_certificate", "TSA certificate has no extendedKeyUsage extension") from None
    if not eku.critical:
        raise InputRejected("invalid_tsa_certificate", "TSA certificate extendedKeyUsage must be critical (RFC 3161 2.3)")
    usages = list(eku.value)
    if usages != [ExtendedKeyUsageOID.TIME_STAMPING]:
        raise InputRejected("invalid_tsa_certificate", "TSA certificate extendedKeyUsage must contain only id-kp-timeStamping")
    now = at or dt.datetime.now(dt.timezone.utc)
    if not (cert.not_valid_before_utc <= now <= cert.not_valid_after_utc):
        raise InputRejected("tsa_certificate_expired", "TSA certificate is not valid at the current time")
    public_key = cert.public_key()
    if isinstance(public_key, rsa.RSAPublicKey):
        if public_key.key_size < 2048:
            raise InputRejected("invalid_tsa_certificate", "TSA RSA key must have at least 2048 bits")
    elif isinstance(public_key, ec.EllipticCurvePublicKey):
        if public_key.curve.name not in ("secp256r1", "secp384r1"):
            raise InputRejected("invalid_tsa_certificate", "TSA EC key must use P-256 or P-384")
    else:
        raise InputRejected("invalid_tsa_certificate", "TSA key must be RSA or ECDSA")


def load_tsa_credentials(pfx: Path, pass_env: str) -> TsaCredentials:
    passphrase = read_passphrase(pass_env)
    if not pfx.is_file():
        raise InputRejected("tsa_pfx_not_found", "TSA PKCS#12 file not found")
    try:
        key, cert, extra = pkcs12.load_key_and_certificates(pfx.read_bytes(), passphrase.encode("utf-8"))
    except OSError as exc:
        raise ProcessingError("read_failed", f"could not read the TSA PKCS#12 file: {type(exc).__name__}") from exc
    except Exception as exc:  # noqa: BLE001 - wrong passphrase / corrupt container
        raise InputRejected("invalid_pkcs12", f"cannot open the TSA PKCS#12 container: {type(exc).__name__}") from exc
    finally:
        del passphrase
    if key is None or cert is None:
        raise InputRejected("invalid_pkcs12", "the TSA PKCS#12 container must hold a private key and its certificate")
    if key.public_key().public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo) != cert.public_key().public_bytes(
        serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo
    ):
        raise InputRejected("invalid_pkcs12", "the TSA private key does not match its certificate")
    check_tsa_certificate(cert)
    return TsaCredentials(key=key, cert=_to_asn1(cert), crypto_cert=cert, chain=[_to_asn1(c) for c in (extra or [])])


# --------------------------------------------------------------------------- requests


def build_request(digest: bytes, algorithm: str, nonce: Optional[int] = None, cert_req: bool = True, policy_oid: Optional[str] = None) -> tsp.TimeStampReq:
    fields: Dict[str, Any] = {
        "version": "v1",
        "message_imprint": tsp.MessageImprint({"hash_algorithm": algos.DigestAlgorithm({"algorithm": algorithm}), "hashed_message": digest}),
        "cert_req": bool(cert_req),
    }
    if nonce is not None:
        fields["nonce"] = int(nonce)
    if policy_oid:
        fields["req_policy"] = policy_oid
    return tsp.TimeStampReq(fields)


def parse_request(der: bytes) -> tsp.TimeStampReq:
    """Parse and fully decode a DER TimeStampReq; anything malformed is ``bad_data_format``."""
    if not der or len(der) > MAX_REQUEST_BYTES:
        raise Rejection("bad_data_format", "empty or oversized TimeStampReq")
    try:
        req = tsp.TimeStampReq.load(der, strict=True)
        req.native  # force full decoding: lazy parsing would hide garbage until later
    except Exception:  # noqa: BLE001
        raise Rejection("bad_data_format", "request is not a DER TimeStampReq") from None
    return req


def check_request(req: tsp.TimeStampReq, policy_oid: str) -> Tuple[str, bytes]:
    """RFC 3161 §2.4.1: refuse what we do not support with the matching ``failInfo``."""
    if req["version"].native != "v1":
        raise Rejection("bad_request", "unsupported TimeStampReq version")
    algorithm = req["message_imprint"]["hash_algorithm"]["algorithm"].native
    if algorithm not in HASH_ALGORITHMS:
        raise Rejection("bad_alg", "hash algorithm not accepted (sha256, sha384, sha512)")
    digest = req["message_imprint"]["hashed_message"].native
    if not isinstance(digest, bytes) or len(digest) != HASH_ALGORITHMS[algorithm]:
        raise Rejection("bad_data_format", "hashed message length does not match the hash algorithm")
    requested_policy = req["req_policy"].native
    if requested_policy is not None and requested_policy != policy_oid:
        raise Rejection("unaccepted_policy", "requested policy is not offered by this TSA")
    extensions = req["extensions"].native
    if extensions:
        raise Rejection("unaccepted_extensions", "request extensions are not supported")
    return algorithm, digest


# --------------------------------------------------------------------------- issuing


def _accuracy(accuracy_ms: int) -> Optional[tsp.Accuracy]:
    if accuracy_ms <= 0:
        return None
    seconds, millis = divmod(int(accuracy_ms), 1000)
    fields: Dict[str, int] = {}
    if seconds:
        fields["seconds"] = seconds
    if millis:
        fields["millis"] = millis
    return tsp.Accuracy(fields)


def _signature_algorithm(key) -> str:
    if isinstance(key, rsa.RSAPrivateKey):
        return "rsassa_pkcs1v15"
    if isinstance(key, ec.EllipticCurvePrivateKey):
        return "sha256_ecdsa"
    raise InputRejected("invalid_tsa_certificate", "TSA key must be RSA or ECDSA")


def _sign(key, data: bytes) -> bytes:
    if isinstance(key, rsa.RSAPrivateKey):
        return key.sign(data, padding.PKCS1v15(), hashes.SHA256())
    return key.sign(data, ec.ECDSA(hashes.SHA256()))


def _placeholder_signature(key) -> bytes:
    """Worst-case-length filler used only for size estimation (never embedded, never issued)."""
    if isinstance(key, rsa.RSAPrivateKey):
        return b"\x00" * (key.key_size // 8)
    return b"\x00" * (2 * ((key.curve.key_size + 7) // 8) + 9)


def build_token(
    req: tsp.TimeStampReq,
    creds: TsaCredentials,
    serial: int,
    policy_oid: str,
    accuracy_ms: int = 1000,
    now: Optional[dt.datetime] = None,
    dry_run: bool = False,
) -> Tuple[cms.ContentInfo, tsp.TSTInfo]:
    """Build (and sign, unless ``dry_run``) the TimeStampToken for an accepted request."""
    gen_time = (now or dt.datetime.now(dt.timezone.utc)).astimezone(dt.timezone.utc)
    gen_time = gen_time.replace(microsecond=(gen_time.microsecond // 1000) * 1000)
    tst_fields: Dict[str, Any] = {
        "version": "v1",
        "policy": policy_oid,
        "message_imprint": req["message_imprint"],
        "serial_number": validate_serial(serial),
        "gen_time": core.GeneralizedTime(gen_time),
        "tsa": asn1_x509.GeneralName(name="directory_name", value=creds.cert.subject),
    }
    accuracy = _accuracy(accuracy_ms)
    if accuracy is not None:
        tst_fields["accuracy"] = accuracy
    if req["nonce"].native is not None:
        tst_fields["nonce"] = req["nonce"]
    tst_info = tsp.TSTInfo(tst_fields)
    tst_der = tst_info.dump()

    signed_attrs = cms.CMSAttributes(
        [
            cms.CMSAttribute({"type": "content_type", "values": ["tst_info"]}),
            cms.CMSAttribute({"type": "signing_time", "values": [cms.Time({"utc_time": core.UTCTime(gen_time.replace(microsecond=0))})]}),
            cms.CMSAttribute({"type": "message_digest", "values": [hashlib.sha256(tst_der).digest()]}),
            cms.CMSAttribute({"type": "signing_certificate_v2", "values": [_signing_certificate_v2(creds.cert)]}),
        ]
    )
    signature = _placeholder_signature(creds.key) if dry_run else _sign(creds.key, signed_attrs.dump())
    digest_algorithm = algos.DigestAlgorithm({"algorithm": SIGNING_DIGEST})
    signer_info = cms.SignerInfo(
        {
            "version": "v1",
            "sid": cms.SignerIdentifier(
                {"issuer_and_serial_number": cms.IssuerAndSerialNumber({"issuer": creds.cert.issuer, "serial_number": creds.cert.serial_number})}
            ),
            "digest_algorithm": digest_algorithm,
            "signed_attrs": signed_attrs,
            "signature_algorithm": algos.SignedDigestAlgorithm({"algorithm": _signature_algorithm(creds.key)}),
            "signature": signature,
        }
    )
    signed_data: Dict[str, Any] = {
        "version": "v3",
        "digest_algorithms": cms.DigestAlgorithms([digest_algorithm]),
        "encap_content_info": cms.EncapsulatedContentInfo({"content_type": "tst_info", "content": cms.ParsableOctetString(tst_der)}),
        "signer_infos": [signer_info],
    }
    # RFC 3161 §2.4.1: certReq=true -> the TSA certificate MUST be present; false -> MUST NOT.
    if req["cert_req"].native:
        signed_data["certificates"] = [creds.cert, *[c for c in creds.chain if c.dump() != creds.cert.dump()]]
    token = cms.ContentInfo({"content_type": "signed_data", "content": cms.SignedData(signed_data)})
    return token, tst_info


def _signing_certificate_v2(cert: asn1_x509.Certificate) -> tsp.SigningCertificateV2:
    """ESSCertIDv2 with SHA-256 (RFC 5816), instead of the SHA-1 ESSCertID."""
    return tsp.SigningCertificateV2(
        {
            "certs": [
                tsp.ESSCertIDv2(
                    {
                        "hash_algorithm": {"algorithm": "sha256"},
                        "cert_hash": hashlib.sha256(cert.dump()).digest(),
                        "issuer_serial": {
                            "issuer": [asn1_x509.GeneralName(name="directory_name", value=cert.issuer)],
                            "serial_number": cert.serial_number,
                        },
                    }
                )
            ]
        }
    )


def granted_response(token: cms.ContentInfo) -> TimeStampResponse:
    return TimeStampResponse({"status": tsp.PKIStatusInfo({"status": "granted"}), "time_stamp_token": token})


def rejection_response(fail_info: str, text: str) -> TimeStampResponse:
    return TimeStampResponse(
        {"status": tsp.PKIStatusInfo({"status": "rejection", "status_string": [text], "fail_info": tsp.PKIFailureInfo({fail_info})})}
    )


def describe_token(token: cms.ContentInfo, tst_info: tsp.TSTInfo, creds_cert: Optional[asn1_x509.Certificate]) -> Dict[str, Any]:
    imprint = tst_info["message_imprint"]
    accuracy = tst_info["accuracy"].native or {}
    accuracy_ms = None
    if accuracy:
        accuracy_ms = int(accuracy.get("seconds") or 0) * 1000 + int(accuracy.get("millis") or 0)
    info: Dict[str, Any] = {
        "serial": str(tst_info["serial_number"].native),
        "gen_time": _iso(tst_info["gen_time"].native),
        "policy_oid": tst_info["policy"].dotted,
        "hash_algorithm": imprint["hash_algorithm"]["algorithm"].native,
        "imprint_hex": imprint["hashed_message"].native.hex(),
        "nonce": None if tst_info["nonce"].native is None else str(tst_info["nonce"].native),
        "accuracy_ms": accuracy_ms,
        "token_sha256": hashlib.sha256(token.dump()).hexdigest(),
        "token_size_bytes": len(token.dump()),
    }
    if creds_cert is not None:
        summary = cert_summary(_to_crypto(creds_cert))
        info["tsa_subject"] = summary["subject"]
        info["tsa_issuer"] = summary["issuer"]
        info["tsa_cert_fingerprint_sha256"] = summary["cert_fingerprint_sha256"]
        info["tsa_cert_not_after"] = summary["not_after"]
    return info


# --------------------------------------------------------------------------- serial file (standalone mode)


def allocate_serial_from_file(path: Path) -> int:
    """Next serial from a counter file guarded by an OS lock (the ``openssl ts`` bug, fixed).

    The platform itself allocates serials from a database sequence; this exists for
    standalone/dev use of ``tsa-issue`` and is exercised by the concurrency test.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    lock_path = path.with_name(path.name + ".lock")
    fd = os.open(str(lock_path), os.O_RDWR | os.O_CREAT, 0o600)
    try:
        _lock(fd)
        try:
            current = 0
            if path.exists():
                text = path.read_text(encoding="ascii").strip()
                current = int(text) if text else 0
            serial = validate_serial(current + 1)
            tmp = path.with_name(path.name + ".tmp")
            with open(tmp, "w", encoding="ascii") as handle:
                handle.write(str(serial))
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(tmp, path)
            return serial
        finally:
            _unlock(fd)
    except ValueError as exc:
        raise ProcessingError("serial_file_corrupt", "serial file does not contain an integer") from exc
    finally:
        os.close(fd)


def _lock(fd: int) -> None:
    if sys.platform == "win32":
        import msvcrt

        deadline = time.monotonic() + 30
        while True:
            try:
                os.lseek(fd, 0, os.SEEK_SET)
                msvcrt.locking(fd, msvcrt.LK_NBLCK, 1)
                return
            except OSError:
                if time.monotonic() > deadline:
                    raise ProcessingError("serial_lock_timeout", "could not lock the serial file") from None
                time.sleep(0.01)
    else:
        import fcntl

        fcntl.flock(fd, fcntl.LOCK_EX)


def _unlock(fd: int) -> None:
    if sys.platform == "win32":
        import msvcrt

        os.lseek(fd, 0, os.SEEK_SET)
        msvcrt.locking(fd, msvcrt.LK_UNLCK, 1)
    else:
        import fcntl

        fcntl.flock(fd, fcntl.LOCK_UN)


# --------------------------------------------------------------------------- tsa-issue


def issue(
    out_path: Path,
    tsa_pfx: Path,
    pass_env: str,
    policy_oid: str,
    serial: Optional[int] = None,
    serial_file: Optional[Path] = None,
    request_path: Optional[Path] = None,
    digest_hex: Optional[str] = None,
    hash_algorithm: str = "sha256",
    nonce: Optional[int] = None,
    cert_req: bool = True,
    accuracy_ms: int = 1000,
    token_out: Optional[Path] = None,
) -> Dict[str, Any]:
    policy_oid = validate_policy_oid(policy_oid)
    if (serial is None) == (serial_file is None):
        raise UsageError("serial_required", "give exactly one of --serial or --serial-file")
    if (request_path is None) == (digest_hex is None):
        raise UsageError("input_required", "give exactly one of --request or --digest")
    if accuracy_ms < 0 or accuracy_ms > 60_000:
        raise UsageError("invalid_accuracy", "--accuracy-ms must be between 0 and 60000")
    if serial is not None:
        validate_serial(serial)

    creds = load_tsa_credentials(tsa_pfx, pass_env)

    if request_path is not None:
        if not request_path.is_file():
            raise InputRejected("missing_input", "TimeStampReq file not found")
        raw = request_path.read_bytes()
    else:
        raw = build_request(parse_digest(digest_hex or "", hash_algorithm), hash_algorithm.lower(), nonce=nonce, cert_req=cert_req).dump()

    result: Dict[str, Any] = {"ok": True, "tsa_kind": TSA_KIND, "label": TSA_LABEL, "tsa_certificate_test": creds.is_test}
    try:
        req = parse_request(raw)
        check_request(req, policy_oid)
    except Rejection as rejection:
        response = rejection_response(rejection.fail_info, rejection.text)
        _write(out_path, response.dump())
        result.update({"status": "rejection", "fail_info": rejection.fail_info, "status_text": rejection.text, "serial": None, "response_path": str(out_path)})
        return result

    if serial is None:
        serial = allocate_serial_from_file(serial_file)  # type: ignore[arg-type]

    token, tst_info = build_token(req, creds, serial, policy_oid, accuracy_ms)
    response = granted_response(token)
    _write(out_path, response.dump())
    if token_out is not None:
        _write(token_out, token.dump())
    result.update({"status": "granted", "fail_info": None, "response_path": str(out_path), "token_path": str(token_out) if token_out else None})
    result.update(describe_token(token, tst_info, creds.cert))
    result["cert_req"] = bool(req["cert_req"].native)
    return result


def _write(path: Path, data: bytes) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
    except OSError as exc:
        raise ProcessingError("write_failed", f"could not write output: {type(exc).__name__}") from exc


# --------------------------------------------------------------------------- tsa-verify


def _load_token(data: bytes) -> Tuple[Optional[str], cms.ContentInfo]:
    """Accept a TimeStampResp (``.tsr``) or a bare TimeStampToken; return (pki_status, token)."""
    try:
        resp = TimeStampResponse.load(data, strict=True)
        status = resp["status"]["status"].native
        token = resp["time_stamp_token"]
        if status is not None:
            if token.native is None:
                return status, None  # type: ignore[return-value]
            return status, token
    except Exception:  # noqa: BLE001
        pass
    try:
        token = cms.ContentInfo.load(data, strict=True)
        token.native
        return None, token
    except Exception as exc:  # noqa: BLE001
        raise InputRejected("invalid_token", "file is neither a TimeStampResp nor a TimeStampToken") from exc


def _find_signer_cert(signed_data: cms.SignedData, extra: List[asn1_x509.Certificate]) -> Optional[asn1_x509.Certificate]:
    sid = signed_data["signer_infos"][0]["sid"].chosen
    candidates = [c.chosen for c in signed_data["certificates"]] if signed_data["certificates"].native else []
    candidates += extra
    for cert in candidates:
        if isinstance(sid, cms.IssuerAndSerialNumber):
            if cert.issuer.dump() == sid["issuer"].dump() and cert.serial_number == sid["serial_number"].native:
                return cert
        elif cert.key_identifier is not None and cert.key_identifier == sid.native:
            return cert
    return None


def verify(
    token_path: Path,
    digest_hex: Optional[str] = None,
    data_path: Optional[Path] = None,
    hash_algorithm: Optional[str] = None,
    trust_paths: Optional[List[Path]] = None,
    tsa_cert_paths: Optional[List[Path]] = None,
    expected_nonce: Optional[int] = None,
    expected_policy: Optional[str] = None,
) -> Dict[str, Any]:
    from pyhanko.sign.validation.generic_cms import validate_tst_signed_data
    from pyhanko.sign.validation.status import TimestampSignatureStatus
    from pyhanko_certvalidator import ValidationContext

    if (digest_hex is None) == (data_path is None):
        raise UsageError("input_required", "give exactly one of --digest or --data")
    if not token_path.is_file():
        raise InputRejected("missing_input", "token file not found")
    if expected_policy is not None:
        expected_policy = validate_policy_oid(expected_policy)

    roots = load_certificates(trust_paths or [])
    extra = load_certificates(tsa_cert_paths or [], code="invalid_tsa_cert_file")
    pki_status, token = _load_token(token_path.read_bytes())

    errors: List[str] = []
    result: Dict[str, Any] = {
        "ok": True,
        "valid": False,
        "trusted": False,
        "trust_reason": None,
        "pki_status": pki_status,
        "granted": pki_status in (None, "granted", "granted_with_mods"),
        "imprint_matches": False,
        "signature_intact": False,
        "signature_valid": False,
        "ess_cert_id_matches": False,
        "eku_ok": False,
        "policy_matches": None,
        "nonce_matches": None,
        "trust_roots_configured": len(roots),
        "revocation": "not_checked",
        "tsa_kind_hint": TSA_KIND,
        "errors": errors,
    }
    if token is None:
        errors.append("no_token: the response carries no TimeStampToken")
        result["granted"] = False
        return result
    if not result["granted"]:
        errors.append(f"not_granted: PKIStatus is {pki_status}")

    if token["content_type"].native != "signed_data":
        raise InputRejected("invalid_token", "token is not a CMS SignedData")
    signed_data: cms.SignedData = token["content"]
    try:
        tst_info = signed_data["encap_content_info"]["content"].parsed
    except Exception as exc:  # noqa: BLE001
        raise InputRejected("invalid_token", "token does not encapsulate a TSTInfo") from exc
    if not isinstance(tst_info, tsp.TSTInfo):
        raise InputRejected("invalid_token", "token does not encapsulate a TSTInfo")

    token_algorithm = tst_info["message_imprint"]["hash_algorithm"]["algorithm"].native
    algorithm = (hash_algorithm or token_algorithm or "").lower()
    if data_path is not None:
        if not data_path.is_file():
            raise InputRejected("missing_input", "data file not found")
        if algorithm not in HASH_ALGORITHMS:
            raise UsageError("unsupported_hash_algorithm", "hash algorithm must be sha256, sha384 or sha512")
        expected_digest = hashlib.new(algorithm, data_path.read_bytes()).digest()
    else:
        expected_digest = parse_digest(digest_hex or "", algorithm)

    signer_cert = _find_signer_cert(signed_data, extra)
    result.update(describe_token(token, tst_info, signer_cert))
    if signer_cert is None:
        errors.append("tsa_certificate_unavailable: token has no TSA certificate and none was supplied")
        return result
    result["tsa_certificate_test"] = any(TEST_MARKER in str(v).upper() for v in (signer_cert.subject.native or {}).values())

    # 1. imprint: the token must be about exactly these bytes.
    result["imprint_matches"] = token_algorithm == algorithm and tst_info["message_imprint"]["hashed_message"].native == expected_digest
    if not result["imprint_matches"]:
        errors.append("imprint_mismatch: the token was not issued for this digest")

    # 2. TSA certificate profile (RFC 3161 2.3) and ESSCertIDv2 binding (RFC 5816).
    try:
        check_tsa_certificate(_to_crypto(signer_cert), at=tst_info["gen_time"].native)
        result["eku_ok"] = True
    except InputRejected as exc:
        errors.append(f"tsa_certificate: {exc.message}")
    result["ess_cert_id_matches"] = _ess_binding_ok(signed_data, signer_cert)
    if not result["ess_cert_id_matches"]:
        errors.append("ess_cert_id_mismatch: SigningCertificateV2 does not identify the signing certificate")

    if expected_policy is not None:
        result["policy_matches"] = tst_info["policy"].dotted == expected_policy
        if not result["policy_matches"]:
            errors.append("policy_mismatch")
    if expected_nonce is not None:
        result["nonce_matches"] = tst_info["nonce"].native == expected_nonce
        if not result["nonce_matches"]:
            errors.append("nonce_mismatch")

    # 3. CMS signature + chain, delegated to pyHanko (independent of the issuing code above).
    if not signed_data["certificates"].native and extra:
        # certReq=false: the token carries no certificate; supply it to a LOCAL copy (the
        # certificates field is outside the signed content, so this changes nothing signed).
        signed_data = signed_data.copy()
        signed_data["certificates"] = [signer_cert]
    anchors = roots if roots else [signer_cert]
    context = ValidationContext(trust_roots=anchors, other_certs=extra, allow_fetching=False, revocation_mode="soft-fail")
    try:
        kwargs = asyncio.run(validate_tst_signed_data(signed_data, context, lambda _alg: tst_info["message_imprint"]["hashed_message"].native))
        status = TimestampSignatureStatus(**kwargs)
        result["signature_intact"] = bool(status.intact)
        result["signature_valid"] = bool(status.valid)
        chain_ok = bool(status.trusted)
        indic = status.trust_problem_indic
    except Exception as exc:  # noqa: BLE001
        errors.append(f"validation_error: {type(exc).__name__}")
        chain_ok, indic = False, None
    if not result["signature_intact"]:
        errors.append("digest_mismatch: the signed TSTInfo was modified")
    if not result["signature_valid"]:
        errors.append("invalid_signature: cryptographic verification failed")

    result["valid"] = bool(
        result["granted"]
        and result["imprint_matches"]
        and result["signature_intact"]
        and result["signature_valid"]
        and result["eku_ok"]
        and result["ess_cert_id_matches"]
        and result["policy_matches"] is not False
        and result["nonce_matches"] is not False
    )
    if roots:
        result["trusted"] = bool(result["valid"] and chain_ok)
        if not result["trusted"]:
            result["trust_reason"] = getattr(indic, "name", None) or ("token_invalid" if not result["valid"] else "no_validation_path")
    else:
        result["trust_reason"] = "no_trust_roots_configured"
    return result


def _ess_binding_ok(signed_data: cms.SignedData, cert: asn1_x509.Certificate) -> bool:
    try:
        for attr in signed_data["signer_infos"][0]["signed_attrs"]:
            name = attr["type"].native
            for value in attr["values"]:
                if name == "signing_certificate_v2":
                    for ess in value["certs"]:
                        algorithm = ess["hash_algorithm"]["algorithm"].native
                        if hashlib.new(algorithm, cert.dump()).digest() == ess["cert_hash"].native:
                            return True
                elif name == "signing_certificate":
                    for ess in value["certs"]:
                        if hashlib.sha1(cert.dump()).digest() == ess["cert_hash"].native:
                            return True
    except Exception:  # noqa: BLE001
        return False
    return False


# --------------------------------------------------------------------------- tsa-gen-test


def _ensure_test_cn(subject: str) -> x509.Name:
    try:
        name = x509.Name.from_rfc4514_string(subject)
    except ValueError as exc:
        raise UsageError("invalid_subject", f"subject is not a valid RFC 4514 string: {exc}") from exc
    rdns = []
    found = False
    for rdn in name.rdns:
        attrs = []
        for attr in rdn:
            if attr.oid == NameOID.COMMON_NAME:
                found = True
                if TEST_MARKER not in str(attr.value).upper():
                    attr = x509.NameAttribute(NameOID.COMMON_NAME, f"{attr.value} {TEST_MARKER}")
            attrs.append(attr)
        rdns.append(x509.RelativeDistinguishedName(attrs))
    if not found:
        rdns.insert(0, x509.RelativeDistinguishedName([x509.NameAttribute(NameOID.COMMON_NAME, f"AssinaVelox TSA {TEST_MARKER}")]))
    return x509.Name(rdns)


def generate_test_tsa(
    out_pfx: Path,
    pass_env: str,
    out_root_pem: Path,
    out_chain_pem: Optional[Path] = None,
    subject: str = DEFAULT_TSA_SUBJECT,
    root_subject: str = DEFAULT_ROOT_SUBJECT,
    days: int = 365,
    key_type: str = "rsa-3072",
) -> Dict[str, Any]:
    passphrase = read_passphrase(pass_env)
    if days < 1 or days > 3650:
        raise UsageError("invalid_days", "--days must be between 1 and 3650")
    tsa_name = _ensure_test_cn(subject)
    root_name = _ensure_test_cn(root_subject)

    def new_key():
        if key_type == "ec-p256":
            return ec.generate_private_key(ec.SECP256R1())
        if key_type == "rsa-3072":
            return rsa.generate_private_key(public_exponent=65537, key_size=3072)
        raise UsageError("invalid_key_type", "--key must be rsa-3072 or ec-p256")

    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
    root_key, tsa_key = new_key(), new_key()
    root_ski = x509.SubjectKeyIdentifier.from_public_key(root_key.public_key())
    root = (
        x509.CertificateBuilder()
        .subject_name(root_name)
        .issuer_name(root_name)
        .public_key(root_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - dt.timedelta(minutes=5))
        .not_valid_after(now + dt.timedelta(days=days + 1))
        .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
        .add_extension(
            x509.KeyUsage(False, False, False, False, False, True, True, False, False),
            critical=True,
        )
        .add_extension(root_ski, critical=False)
        .sign(root_key, hashes.SHA256())
    )
    tsa_cert = (
        x509.CertificateBuilder()
        .subject_name(tsa_name)
        .issuer_name(root_name)
        .public_key(tsa_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - dt.timedelta(minutes=5))
        .not_valid_after(now + dt.timedelta(days=days))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(True, True, False, False, False, False, False, False, False),
            critical=True,
        )
        .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.TIME_STAMPING]), critical=True)
        .add_extension(x509.SubjectKeyIdentifier.from_public_key(tsa_key.public_key()), critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(root_ski), critical=False)
        .sign(root_key, hashes.SHA256())
    )
    try:
        pfx_bytes = pkcs12.serialize_key_and_certificates(
            name=b"AssinaVelox TSA TESTE",
            key=tsa_key,
            cert=tsa_cert,
            cas=[root],
            encryption_algorithm=serialization.BestAvailableEncryption(passphrase.encode("utf-8")),
        )
        for path in (out_pfx, out_root_pem, out_chain_pem):
            if path is not None:
                path.parent.mkdir(parents=True, exist_ok=True)
        out_pfx.write_bytes(pfx_bytes)
        out_root_pem.write_bytes(root.public_bytes(serialization.Encoding.PEM))
        if out_chain_pem is not None:
            out_chain_pem.write_bytes(tsa_cert.public_bytes(serialization.Encoding.PEM) + root.public_bytes(serialization.Encoding.PEM))
    except OSError as exc:
        raise ProcessingError("write_failed", f"could not write TSA files: {type(exc).__name__}") from exc
    finally:
        del passphrase
        del root_key

    tsa_summary = cert_summary(tsa_cert)
    root_summary = cert_summary(root)
    return {
        "ok": True,
        "pfx_path": str(out_pfx),
        "root_pem_path": str(out_root_pem),
        "chain_pem_path": str(out_chain_pem) if out_chain_pem else None,
        "tsa_subject": tsa_summary["subject"],
        "tsa_cert_fingerprint_sha256": tsa_summary["cert_fingerprint_sha256"],
        "not_before": tsa_summary["not_before"],
        "not_after": tsa_summary["not_after"],
        "root_subject": root_summary["subject"],
        "root_cert_fingerprint_sha256": root_summary["cert_fingerprint_sha256"],
        "key_algorithm": key_type.upper(),
        "eku": ["timeStamping (critical)"],
        "test_only": True,
        "tsa_kind": TSA_KIND,
        "warning": TEST_WARNING,
    }
