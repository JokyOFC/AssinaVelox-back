"""Revisao adversarial I-3A: ``embed-external`` no modo CMS aceita atributos fora do PAdES-B-B.

``_check_cms`` (pdftool/external.py) confere so ``message_digest`` e ``content_type`` dos
atributos assinados. Um CMS pronto SEM o atributo ESS signing-certificate-v2 (obrigatorio no
PAdES baseline, ETSI EN 319 142-1 §6.3; e o que liga o certificado a assinatura) ou COM o
atributo ``signing-time`` (proibido no PAdES: a hora declarada vai no ``/M``) e embutido e
devolvido como ``"profile": "PAdES-B-B"`` -- e o PHP grava ``participant_signatures.profile``
com esse valor (``ExternalSignatureService::record``), anunciado nas paginas (T2).

O CMS e montado aqui a mao, com a chave de TESTE do "servico remoto".
"""

import datetime as dt

import pytest
from asn1crypto import algos, cms
from asn1crypto import x509 as asn1_x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding

import test_external as te
from conftest import make_pdf


@pytest.fixture
def remote(tmp_path, run_cli, monkeypatch):
    return te._participant(tmp_path, run_cli, monkeypatch, "Titular Servico Remoto", "REVIEW_CMS_PASS", "senha-review-cms-91!")


def _bare_cms(who, document_digest_hex, extra_attrs=()):
    cert = asn1_x509.Certificate.load(who["cert"].public_bytes(serialization.Encoding.DER))
    signed_attrs = cms.CMSAttributes([
        cms.CMSAttribute({"type": "content_type", "values": ["data"]}),
        cms.CMSAttribute({"type": "message_digest", "values": [bytes.fromhex(document_digest_hex)]}),
        *extra_attrs,
    ])
    signature = who["key"].sign(signed_attrs.dump(), padding.PKCS1v15(), hashes.SHA256())
    signer_info = cms.SignerInfo({
        "version": "v1",
        "sid": cms.SignerIdentifier({
            "issuer_and_serial_number": cms.IssuerAndSerialNumber({"issuer": cert.issuer, "serial_number": cert.serial_number}),
        }),
        "digest_algorithm": algos.DigestAlgorithm({"algorithm": "sha256"}),
        "signed_attrs": signed_attrs,
        "signature_algorithm": algos.SignedDigestAlgorithm({"algorithm": "sha256_rsa"}),
        "signature": signature,
    })
    signed_data = cms.SignedData({
        "version": "v1",
        "digest_algorithms": [algos.DigestAlgorithm({"algorithm": "sha256"})],
        "encap_content_info": {"content_type": "data"},
        "certificates": [cert],
        "signer_infos": [signer_info],
    })
    return cms.ContentInfo({"content_type": "signed_data", "content": signed_data}).dump()


def _embed(run_cli, tmp_path, remote, extra_attrs, tag):
    src = make_pdf(tmp_path / f"{tag}-base.pdf", [{}])
    code, prep, pending, state = te._prepare(run_cli, tmp_path, src, remote, tag)
    assert code == 0, prep
    blob = tmp_path / f"{tag}.p7s"
    blob.write_bytes(_bare_cms(remote, prep["document_digest_hex"], extra_attrs))
    return run_cli("embed-external", "--pending", pending, "--state", state, "--out", tmp_path / f"{tag}-signed.pdf",
                   "--cms", blob, "--expect-fingerprint", remote["fingerprint"])


def test_cms_without_signing_certificate_v2_is_not_embedded_as_pades_b_b(tmp_path, run_cli, remote):
    code, res = _embed(run_cli, tmp_path, remote, (), "sem-ess")
    assert code != 0 or res.get("profile") != "PAdES-B-B", (
        "CMS sem signing-certificate-v2 foi aceito e anunciado como PAdES-B-B: " + str({k: res.get(k) for k in ("ok", "mode", "profile")})
    )


def test_cms_with_signing_time_attribute_is_not_embedded_as_pades_b_b(tmp_path, run_cli, remote):
    signing_time = cms.CMSAttribute({
        "type": "signing_time",
        "values": [cms.Time({"utc_time": dt.datetime(2020, 1, 1, tzinfo=dt.timezone.utc)})],
    })
    code, res = _embed(run_cli, tmp_path, remote, (signing_time,), "com-signing-time")
    assert code != 0 or res.get("profile") != "PAdES-B-B", (
        "CMS com signing-time foi aceito e anunciado como PAdES-B-B: " + str({k: res.get(k) for k in ("ok", "mode", "profile")})
    )
