"""Dublê do TOKEN nos testes do P3-EXT (ferramenta de TESTE, nunca da aplicação).

Faz o que o token/cartão do participante faria fora do servidor: guarda a chave (num arquivo
do diretório de trabalho do teste), devolve o certificado e assina um digest pronto (modo
``raw``) ou monta um CMS/PKCS#7 destacado sobre o resumo do documento (modo ``cms``).
Certificados de TESTE (CN com "TESTE", AC descartável) — nunca ICP-Brasil.

    gen      --out-dir D --name N [--a3]      -> {"certificate", "chain", "fingerprint", "ca_pem"}
    sign-raw --dir D --digest-b64 X           -> {"signature"}
    sign-cms --dir D --digest-b64 X           -> {"cms"}
"""

import argparse
import asyncio
import base64
import datetime as dt
import hashlib
import json
import sys
from pathlib import Path

from asn1crypto import keys as asn1_keys
from asn1crypto import x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.asymmetric.utils import Prehashed
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID

A3_POLICY = "2.16.76.1.2.3.1"


def _gen(out_dir: Path, name: str, a3: bool) -> dict:
    out_dir.mkdir(parents=True, exist_ok=True)
    now = dt.datetime.now(dt.timezone.utc)
    ca_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    ca_name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "AC TESTE Token Simulado"), x509.NameAttribute(NameOID.COUNTRY_NAME, "BR")])
    ca = (x509.CertificateBuilder().subject_name(ca_name).issuer_name(ca_name).public_key(ca_key.public_key())
          .serial_number(x509.random_serial_number())
          .not_valid_before(now - dt.timedelta(days=1)).not_valid_after(now + dt.timedelta(days=365))
          .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
          .add_extension(x509.KeyUsage(False, False, False, False, False, True, True, False, False), critical=True)
          .add_extension(x509.SubjectKeyIdentifier.from_public_key(ca_key.public_key()), critical=False)
          .sign(ca_key, hashes.SHA256()))
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, f"{name} TESTE"), x509.NameAttribute(NameOID.COUNTRY_NAME, "BR")])
    builder = (x509.CertificateBuilder().subject_name(subject).issuer_name(ca_name).public_key(key.public_key())
               .serial_number(x509.random_serial_number())
               .not_valid_before(now - dt.timedelta(minutes=5)).not_valid_after(now + dt.timedelta(days=30))
               .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
               .add_extension(x509.KeyUsage(True, True, False, False, False, False, False, False, False), critical=True)
               .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.CLIENT_AUTH, ExtendedKeyUsageOID.EMAIL_PROTECTION]), critical=False)
               .add_extension(x509.AuthorityKeyIdentifier.from_issuer_public_key(ca_key.public_key()), critical=False))
    if a3:
        builder = builder.add_extension(
            x509.CertificatePolicies([x509.PolicyInformation(x509.ObjectIdentifier(A3_POLICY), None)]), critical=False)
    cert = builder.sign(ca_key, hashes.SHA256())

    (out_dir / "key.pem").write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    (out_dir / "cert.pem").write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    (out_dir / "ca.pem").write_bytes(ca.public_bytes(serialization.Encoding.PEM))
    der = cert.public_bytes(serialization.Encoding.DER)
    return {
        "certificate": base64.b64encode(der).decode(),
        "chain": [base64.b64encode(ca.public_bytes(serialization.Encoding.DER)).decode()],
        "fingerprint": hashlib.sha256(der).hexdigest(),
        "ca_pem": str(out_dir / "ca.pem"),
        "key_pem": str(out_dir / "key.pem"),
    }


def _key(directory: Path):
    return serialization.load_pem_private_key((directory / "key.pem").read_bytes(), password=None)


def _sign_raw(directory: Path, digest_b64: str) -> dict:
    digest = base64.b64decode(digest_b64)
    signature = _key(directory).sign(digest, padding.PKCS1v15(), Prehashed(hashes.SHA256()))
    return {"signature": base64.b64encode(signature).decode()}


def _sign_cms(directory: Path, digest_b64: str) -> dict:
    from pyhanko.sign import signers
    from pyhanko_certvalidator.registry import SimpleCertificateStore

    cert = asn1_x509.Certificate.load(x509.load_pem_x509_certificate((directory / "cert.pem").read_bytes()).public_bytes(serialization.Encoding.DER))
    ca = asn1_x509.Certificate.load(x509.load_pem_x509_certificate((directory / "ca.pem").read_bytes()).public_bytes(serialization.Encoding.DER))
    key = asn1_keys.PrivateKeyInfo.load(_key(directory).private_bytes(serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    signer = signers.SimpleSigner(signing_cert=cert, signing_key=key, cert_registry=SimpleCertificateStore.from_certs([cert, ca]))
    cms = asyncio.run(signer.async_sign(base64.b64decode(digest_b64), "sha256", use_pades=True))
    return {"cms": base64.b64encode(cms.dump()).decode()}


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("gen")
    p.add_argument("--out-dir", required=True)
    p.add_argument("--name", required=True)
    p.add_argument("--a3", action="store_true")
    for name in ("sign-raw", "sign-cms"):
        p = sub.add_parser(name)
        p.add_argument("--dir", required=True)
        p.add_argument("--digest-b64", required=True)
    args = parser.parse_args(argv)
    if args.cmd == "gen":
        out = _gen(Path(args.out_dir), args.name, args.a3)
    elif args.cmd == "sign-raw":
        out = _sign_raw(Path(args.dir), args.digest_b64)
    else:
        out = _sign_cms(Path(args.dir), args.digest_b64)
    sys.stdout.write(json.dumps(out) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
