"""Gera, em tempo de teste, uma AC de teste e certificados de servidor (HttpsPinTest).

Uso: python make_certs.py <diretório> <nome-do-host>

Arquivos gerados (PEM):
  ca.pem                     AC de teste (a ÚNICA em que o cliente confia no teste)
  good.pem / good.key        folha para <nome-do-host> (SAN DNS), emitida pela AC de teste
  wrong.pem / wrong.key      folha para "outro.<domínio>" (SAN DNS), emitida pela AC de teste
  iponly.pem / iponly.key    folha só com SAN IP 127.0.0.1 (sem nome), emitida pela AC de teste
  rogue.pem / rogue.key      folha para <nome-do-host>, emitida por OUTRA AC (não confiável)

Nada disto sai do diretório temporário do teste. Não é carregado pela suíte PHP.
"""

import datetime
import ipaddress
import sys
from pathlib import Path

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID


def _key():
    return ec.generate_private_key(ec.SECP256R1())


def _name(common_name):
    return x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, common_name)])


def _now():
    return datetime.datetime.now(datetime.timezone.utc)


def make_ca(common_name):
    key = _key()
    cert = (
        x509.CertificateBuilder()
        .subject_name(_name(common_name))
        .issuer_name(_name(common_name))
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(_now() - datetime.timedelta(minutes=5))
        .not_valid_after(_now() + datetime.timedelta(days=1))
        .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True, content_commitment=False, key_encipherment=False,
                data_encipherment=False, key_agreement=False, key_cert_sign=True,
                crl_sign=True, encipher_only=False, decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(x509.SubjectKeyIdentifier.from_public_key(key.public_key()), critical=False)
        .sign(key, hashes.SHA256())
    )
    return key, cert


def make_leaf(ca_key, ca_cert, common_name, sans):
    key = _key()
    cert = (
        x509.CertificateBuilder()
        .subject_name(_name(common_name))
        .issuer_name(ca_cert.subject)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(_now() - datetime.timedelta(minutes=5))
        .not_valid_after(_now() + datetime.timedelta(days=1))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(x509.SubjectAlternativeName(sans), critical=False)
        .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.SERVER_AUTH]), critical=False)
        .add_extension(
            x509.AuthorityKeyIdentifier.from_issuer_public_key(ca_key.public_key()), critical=False
        )
        .sign(ca_key, hashes.SHA256())
    )
    return key, cert


def write(directory, stem, key, cert):
    (directory / f"{stem}.pem").write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    (directory / f"{stem}.key").write_bytes(
        key.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        )
    )


def main():
    directory = Path(sys.argv[1])
    host = sys.argv[2]
    other = "outro." + host.split(".", 1)[1]
    directory.mkdir(parents=True, exist_ok=True)

    ca_key, ca_cert = make_ca("AssinaVelox AC de TESTE (HttpsPinTest)")
    (directory / "ca.pem").write_bytes(ca_cert.public_bytes(serialization.Encoding.PEM))

    write(directory, "good", *make_leaf(ca_key, ca_cert, host, [x509.DNSName(host)]))
    write(directory, "wrong", *make_leaf(ca_key, ca_cert, other, [x509.DNSName(other)]))
    write(
        directory, "iponly",
        *make_leaf(ca_key, ca_cert, "127.0.0.1", [x509.IPAddress(ipaddress.ip_address("127.0.0.1"))]),
    )

    rogue_key, rogue_cert = make_ca("AC NAO CONFIAVEL (HttpsPinTest)")
    write(directory, "rogue", *make_leaf(rogue_key, rogue_cert, host, [x509.DNSName(host)]))

    print("ok")


if __name__ == "__main__":
    main()
