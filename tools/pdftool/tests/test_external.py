"""P3-EXT: ``prepare-external`` / ``embed-external`` — signature made outside the process.

The "external signer" here is the test itself holding a TEST key (what a token would do):
the tool never sees the key. Certificates come from ``gen-test-participant-cert`` (TEST CA,
CN with "TESTE") or are built in the test for the EC case. Never ICP-Brasil.
"""

import asyncio
import base64
import datetime as dt
import json

import pytest
from asn1crypto import x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, padding
from cryptography.hazmat.primitives.asymmetric.utils import Prehashed
from cryptography.hazmat.primitives.serialization import pkcs12
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID

from conftest import make_pdf, run_subprocess

PASS = "senha-do-token-simulado-77!"
OTHER_PASS = "senha-de-outro-titular-88!"


def _participant(tmp_path, run_cli, monkeypatch, label, env, password, **kwargs):
    monkeypatch.setenv(env, password)
    pfx, ca = tmp_path / f"{label}.pfx", tmp_path / f"{label}-ca.pem"
    extra = []
    for key, value in kwargs.items():
        extra += [f"--{key.replace('_', '-')}", str(value)]
    code, res = run_cli("gen-test-participant-cert", "--out-pfx", pfx, "--pass-env", env, "--name", label, "--out-ca-pem", ca, *extra)
    assert code == 0, res
    bundle = pkcs12.load_pkcs12(pfx.read_bytes(), password.encode())
    cert_pem = tmp_path / f"{label}-cert.pem"
    cert_pem.write_bytes(bundle.cert.certificate.public_bytes(serialization.Encoding.PEM))
    return {
        "key": bundle.key,
        "cert": bundle.cert.certificate,
        "cert_pem": cert_pem,
        "ca": ca,
        "pfx": pfx,
        "env": env,
        "fingerprint": res["cert_fingerprint_sha256"],
    }


@pytest.fixture
def signer(tmp_path, run_cli, monkeypatch):
    return _participant(tmp_path, run_cli, monkeypatch, "Titular Token", "EXT_PASS", PASS)


@pytest.fixture
def other(tmp_path, run_cli, monkeypatch):
    return _participant(tmp_path, run_cli, monkeypatch, "Outro Titular", "EXT_OTHER_PASS", OTHER_PASS)


def _prepare(run_cli, tmp_path, src, who, tag="p", *extra):
    pending, state = tmp_path / f"{tag}-pending.pdf", tmp_path / f"{tag}-state.json"
    code, res = run_cli("prepare-external", "--in", src, "--out", pending, "--state-out", state,
                        "--cert", who["cert_pem"], "--chain", who["ca"], "--field-name", f"AV_Externo_{tag}", *extra)
    return code, res, pending, state


def _raw_sign(who, digest_hex):
    """What the local component does: sign a digest that is ALREADY computed (no re-hash)."""
    key = who["key"]
    if isinstance(key, ec.EllipticCurvePrivateKey):
        return key.sign(bytes.fromhex(digest_hex), ec.ECDSA(Prehashed(hashes.SHA256())))
    return key.sign(bytes.fromhex(digest_hex), padding.PKCS1v15(), Prehashed(hashes.SHA256()))


def _write_b64(path, data):
    path.write_text(base64.b64encode(data).decode("ascii"), encoding="ascii")
    return path


def _embed_raw(run_cli, tmp_path, pending, state, who, signature, tag="p", *extra):
    sig = _write_b64(tmp_path / f"{tag}-sig.b64", signature)
    out = tmp_path / f"{tag}-signed.pdf"
    code, res = run_cli("embed-external", "--pending", pending, "--state", state, "--out", out,
                        "--signature", sig, "--cert", who["cert_pem"], "--chain", who["ca"], *extra)
    return code, res, out


def _cms_for(who, document_digest_hex):
    """A ready CMS/PKCS#7 over the document digest, as a remote service would return it."""
    from pyhanko.sign import signers
    from pyhanko_certvalidator.registry import SimpleCertificateStore
    from asn1crypto import keys as asn1_keys

    cert = asn1_x509.Certificate.load(who["cert"].public_bytes(serialization.Encoding.DER))
    ca = asn1_x509.Certificate.load(x509.load_pem_x509_certificate(who["ca"].read_bytes()).public_bytes(serialization.Encoding.DER))
    key = asn1_keys.PrivateKeyInfo.load(who["key"].private_bytes(
        serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    simple = signers.SimpleSigner(signing_cert=cert, signing_key=key, cert_registry=SimpleCertificateStore.from_certs([cert, ca]))
    return asyncio.run(simple.async_sign(bytes.fromhex(document_digest_hex), "sha256", use_pades=True)).dump()


# --------------------------------------------------------------------------------------


def test_raw_signature_round_trip_is_valid_trusted_and_incremental(tmp_path, run_cli, signer):
    src = make_pdf(tmp_path / "base.pdf", [{}, {"rotate": 90}])
    code, prep, pending, state = _prepare(run_cli, tmp_path, src, signer, "p", "--trust", signer["ca"])
    assert code == 0, prep
    assert prep["profile"] == "PAdES-B-B" and prep["timestamp"] is None
    assert prep["hash_function"] == "SHA256" and prep["signature_mechanism"] == "sha256_rsa"
    assert len(prep["digest_to_sign_hex"]) == 64 and prep["digest_to_sign_hex"] != prep["document_digest_hex"]
    assert prep["certificate"]["cert_fingerprint_sha256"] == signer["fingerprint"]
    assert prep["certificate"]["test_certificate"] is True
    assert prep["certificate"]["icp_brasil"]["chain_validated"] is False
    assert prep["chain_trust"] == {"trusted": True, "reason": None, "trust_roots_configured": 1, "revocation": "not_checked"}
    assert pending.read_bytes().startswith(src.read_bytes())

    code, res, out = _embed_raw(run_cli, tmp_path, pending, state, signer, _raw_sign(signer, prep["digest_to_sign_hex"]),
                                "p", "--trust", signer["ca"])
    assert code == 0, res
    assert res["mode"] == "raw" and res["profile"] == "PAdES-B-B"
    assert res["signature_count"] == 1 and res["previous_signature_count"] == 0
    assert res["prefix_preserved"] is True and res["trusted"] is True and res["revocation"] == "not_checked"
    assert res["chain"]["ok"] is True
    sig = res["validation"]["signatures"][0]
    assert sig["field_name"] == "AV_Externo_p" and sig["intact"] and sig["valid"] and sig["trusted"]
    assert sig["coverage"] == "ENTIRE_FILE" and sig["subfilter"] == "/ETSI.CAdES.detached"
    assert out.read_bytes().startswith(src.read_bytes())
    assert len(out.read_bytes()) == len(pending.read_bytes())

    # And the independent validator agrees.
    code, val = run_cli("validate", "--in", out, "--trust", signer["ca"])
    assert code == 0 and val["all_intact"] and val["all_valid"] and val["all_covering"]


def test_cms_mode_round_trip(tmp_path, run_cli, signer):
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, prep, pending, state = _prepare(run_cli, tmp_path, src, signer)
    assert code == 0, prep
    assert prep["chain_trust"]["trusted"] is False and prep["chain_trust"]["reason"] == "no_trust_roots_configured"

    cms = tmp_path / "ext.p7s"
    cms.write_bytes(_cms_for(signer, prep["document_digest_hex"]))
    out = tmp_path / "signed.pdf"
    code, res = run_cli("embed-external", "--pending", pending, "--state", state, "--out", out, "--cms", cms,
                        "--trust", signer["ca"], "--expect-fingerprint", signer["fingerprint"])
    assert code == 0, res
    assert res["mode"] == "cms" and res["signature_count"] == 1 and res["chain"]["ok"] and res["trusted"] is True

    # Base64 of the same CMS (what a JSON API would carry) is accepted too.
    b64 = _write_b64(tmp_path / "ext.b64", cms.read_bytes())
    code, res = run_cli("embed-external", "--pending", pending, "--state", state, "--out", tmp_path / "signed2.pdf", "--cms", b64)
    assert code == 0, res


def test_signature_over_the_digest_of_another_revision_is_rejected(tmp_path, run_cli, signer):
    one = make_pdf(tmp_path / "one.pdf", [{"text": "Contrato A"}])
    two = make_pdf(tmp_path / "two.pdf", [{"text": "Contrato B"}])
    code, prep_a, pending_a, state_a = _prepare(run_cli, tmp_path, one, signer, "a")
    assert code == 0, prep_a
    code, prep_b, pending_b, state_b = _prepare(run_cli, tmp_path, two, signer, "b")
    assert code == 0, prep_b

    # raw: a signature made for revision A does not fit revision B.
    code, res, out = _embed_raw(run_cli, tmp_path, pending_b, state_b, signer, _raw_sign(signer, prep_a["digest_to_sign_hex"]), "b")
    assert code == 4 and res["error"]["code"] == "signature_invalid"
    assert not out.exists()

    # cms: a CMS over the document digest of A is refused as another revision.
    cms = tmp_path / "a.p7s"
    cms.write_bytes(_cms_for(signer, prep_a["document_digest_hex"]))
    out = tmp_path / "b-cms.pdf"
    code, res = run_cli("embed-external", "--pending", pending_b, "--state", state_b, "--out", out, "--cms", cms)
    assert code == 4 and res["error"]["code"] == "digest_mismatch"
    assert not out.exists()

    # state of A with the pending file of B: the pending file is not the one described.
    code, res, out = _embed_raw(run_cli, tmp_path, pending_b, state_a, signer, _raw_sign(signer, prep_a["digest_to_sign_hex"]), "x")
    assert code == 4 and res["error"]["code"] == "pending_mismatch"


def test_tampered_signature_is_rejected_and_nothing_is_written(tmp_path, run_cli, signer):
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, prep, pending, state = _prepare(run_cli, tmp_path, src, signer)
    assert code == 0, prep
    signature = bytearray(_raw_sign(signer, prep["digest_to_sign_hex"]))
    signature[10] ^= 0x01
    code, res, out = _embed_raw(run_cli, tmp_path, pending, state, signer, bytes(signature))
    assert code == 4 and res["error"]["code"] == "signature_invalid"
    assert not out.exists()

    # A tampered CMS (signature bytes flipped) is refused as well.
    from asn1crypto import cms as asn1_cms
    info = asn1_cms.ContentInfo.load(_cms_for(signer, prep["document_digest_hex"]))
    raw = bytearray(info["content"]["signer_infos"][0]["signature"].native)
    raw[5] ^= 0x01
    info["content"]["signer_infos"][0]["signature"] = asn1_cms.OctetString(bytes(raw))
    cms = tmp_path / "tampered.p7s"
    cms.write_bytes(info.dump(force=True))
    code, res = run_cli("embed-external", "--pending", pending, "--state", state, "--out", tmp_path / "o.pdf", "--cms", cms)
    assert code == 4 and res["error"]["code"] == "signature_invalid"


def test_certificate_different_from_the_announced_one_is_rejected(tmp_path, run_cli, signer, other):
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, prep, pending, state = _prepare(run_cli, tmp_path, src, signer)
    assert code == 0, prep

    # raw: another holder signs the same digest with their own (valid) key and certificate.
    code, res, out = _embed_raw(run_cli, tmp_path, pending, state, other, _raw_sign(other, prep["digest_to_sign_hex"]))
    assert code == 4 and res["error"]["code"] == "certificate_mismatch"
    assert not out.exists()

    # raw: the announced certificate with a signature from another key.
    sig = _write_b64(tmp_path / "mixed.b64", _raw_sign(other, prep["digest_to_sign_hex"]))
    code, res = run_cli("embed-external", "--pending", pending, "--state", state, "--out", tmp_path / "m.pdf",
                        "--signature", sig, "--cert", signer["cert_pem"])
    assert code == 4 and res["error"]["code"] == "signature_invalid"

    # cms: signed by another certificate over the right digest.
    cms = tmp_path / "other.p7s"
    cms.write_bytes(_cms_for(other, prep["document_digest_hex"]))
    code, res = run_cli("embed-external", "--pending", pending, "--state", state, "--out", tmp_path / "c.pdf", "--cms", cms)
    assert code == 4 and res["error"]["code"] == "certificate_mismatch"

    # prepare with --expect-fingerprint of someone else is refused up front.
    code, res, _p, _s = _prepare(run_cli, tmp_path, src, signer, "q", "--expect-fingerprint", other["fingerprint"])
    assert code == 4 and res["error"]["code"] == "certificate_mismatch"


def test_previous_signatures_and_revisions_are_preserved(tmp_path, run_cli, signer, other, test_cert):
    """A1 participant first, then the external signature, then the operator: a sound chain."""
    src = make_pdf(tmp_path / "base.pdf", [{"text": "Base congelada"}])
    one = tmp_path / "one.pdf"
    code, res = run_cli("participant-sign", "--in", src, "--out", one, "--pfx", other["pfx"], "--pass-env", other["env"],
                        "--field-name", "AV_Participante_A1")
    assert code == 0, res

    code, prep, pending, state = _prepare(run_cli, tmp_path, one, signer)
    assert code == 0, prep
    assert prep["previous_signature_count"] == 1
    code, res, two = _embed_raw(run_cli, tmp_path, pending, state, signer, _raw_sign(signer, prep["digest_to_sign_hex"]),
                                "p", "--trust", signer["ca"], "--trust", other["ca"])
    assert code == 0, res
    assert res["signature_count"] == 2 and res["chain"]["ok"] is True
    assert two.read_bytes().startswith(one.read_bytes()) and one.read_bytes().startswith(src.read_bytes())
    first, second = res["validation"]["signatures"]
    assert first["field_name"] == "AV_Participante_A1" and first["coverage"] == "ENTIRE_REVISION"
    assert first["modification_level"] in ("NONE", "FORM_FILLING") and first["intact"] and first["valid"]
    assert second["coverage"] == "ENTIRE_FILE" and second["trusted"] is True

    op_pfx, op_pem, op_env = test_cert
    final = tmp_path / "final.pdf"
    code, res = run_cli("sign", "--in", two, "--out", final, "--pfx", op_pfx, "--pass-env", op_env)
    assert code == 0, res
    from pdftool.participant_sign import analyse_chain
    code, val = run_cli("validate", "--in", final, "--trust", signer["ca"], "--trust", other["ca"], "--trust", op_pem)
    assert code == 0 and val["signature_count"] == 3 and analyse_chain(val)["ok"] is True
    assert all(s["trusted"] for s in val["signatures"])


def test_state_is_minimal_and_carries_no_secret(tmp_path, run_cli, signer):
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, prep, pending, state = _prepare(run_cli, tmp_path, src, signer)
    assert code == 0, prep
    text = state.read_text(encoding="utf-8")
    data = json.loads(text)
    assert set(data) == {
        "format", "field_name", "md_algorithm", "signature_mechanism", "prefer_pss", "document_digest_hex",
        "reserved_region_start", "reserved_region_end", "bytes_reserved", "signed_attrs_der_b64",
        "signed_attrs_digest_hex", "signer_cert_fingerprint_sha256", "chain_fingerprints_sha256", "base_size",
        "base_sha256", "pending_size", "pending_sha256", "previous_signature_count",
    }
    key_der = signer["key"].private_bytes(serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption())
    for needle in (PASS, "PRIVATE KEY", base64.b64encode(key_der[40:80]).decode(), key_der[40:80].hex()):
        assert needle not in text
        assert needle not in json.dumps(prep)


def test_tampered_pending_file_or_state_is_rejected(tmp_path, run_cli, signer):
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, prep, pending, state = _prepare(run_cli, tmp_path, src, signer)
    assert code == 0, prep
    signature = _raw_sign(signer, prep["digest_to_sign_hex"])

    data = bytearray(pending.read_bytes())
    data[20] ^= 0x01
    forged = tmp_path / "forged.pdf"
    forged.write_bytes(bytes(data))
    code, res, _out = _embed_raw(run_cli, tmp_path, forged, state, signer, signature, "f")
    assert code == 4 and res["error"]["code"] == "pending_mismatch"

    broken = json.loads(state.read_text(encoding="utf-8"))
    broken["signed_attrs_digest_hex"] = "00" * 32
    bad_state = tmp_path / "bad-state.json"
    bad_state.write_text(json.dumps(broken), encoding="utf-8")
    code, res, _out = _embed_raw(run_cli, tmp_path, pending, bad_state, signer, signature, "s")
    assert code == 2 and res["error"]["code"] == "state_invalid"


def test_expired_or_non_signing_certificate_is_refused_at_prepare(tmp_path, run_cli, monkeypatch):
    old = _participant(tmp_path, run_cli, monkeypatch, "Vencido", "OLD_PASS", "velha-1!", valid_from_days=-90, days=30)
    enc = _participant(tmp_path, run_cli, monkeypatch, "Cifra", "ENC_PASS", "cifra-2!", key_usage="encipherment")
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, res, pending, _state = _prepare(run_cli, tmp_path, src, old, "o")
    assert code == 4 and res["error"]["code"] == "certificate_expired" and not pending.exists()
    code, res, pending, _state = _prepare(run_cli, tmp_path, src, enc, "e")
    assert code == 4 and res["error"]["code"] == "certificate_not_for_signing" and not pending.exists()


def test_field_name_rules_and_broken_input(tmp_path, run_cli, signer):
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, prep, pending, state = _prepare(run_cli, tmp_path, src, signer, "p")
    assert code == 0
    code, res, signed = _embed_raw(run_cli, tmp_path, pending, state, signer, _raw_sign(signer, prep["digest_to_sign_hex"]))
    assert code == 0, res
    # Same field again on the signed file.
    code, res, _p, _s = _prepare(run_cli, tmp_path, signed, signer, "p")
    assert code == 2 and res["error"]["code"] == "field_name_taken"
    code, res = run_cli("prepare-external", "--in", src, "--out", tmp_path / "x.pdf", "--state-out", tmp_path / "x.json",
                        "--cert", signer["cert_pem"], "--field-name", "nome com espaco")
    assert code == 2 and res["error"]["code"] == "invalid_field_name"

    # A tampered file cannot receive an external signature on top.
    data = signed.read_bytes().replace(b"Helvetica", b"Helvetics", 1)
    tampered = tmp_path / "tampered.pdf"
    tampered.write_bytes(data)
    code, res, _p, _s = _prepare(run_cli, tmp_path, tampered, signer, "t")
    assert code == 4 and res["error"]["code"] == "previous_signature_invalid"

    # Exactly one mode.
    code, res = run_cli("embed-external", "--pending", pending, "--state", state, "--out", tmp_path / "y.pdf")
    assert code == 2 and res["error"]["code"] == "usage_error"


def test_ec_key_raw_signature(tmp_path, run_cli):
    key = ec.generate_private_key(ec.SECP256R1())
    now = dt.datetime.now(dt.timezone.utc)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Titular EC TESTE")])
    cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(now - dt.timedelta(minutes=5)).not_valid_after(now + dt.timedelta(days=5))
            .add_extension(x509.KeyUsage(True, True, False, False, False, False, False, False, False), critical=True)
            .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.EMAIL_PROTECTION]), critical=False)
            .sign(key, hashes.SHA256()))
    pem = tmp_path / "ec.pem"
    pem.write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    who = {"key": key, "cert": cert, "cert_pem": pem, "ca": pem}
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, prep, pending, state = _prepare(run_cli, tmp_path, src, who)
    assert code == 0, prep
    assert prep["signature_mechanism"] == "sha256_ecdsa"
    code, res, _out = _embed_raw(run_cli, tmp_path, pending, state, who, _raw_sign(who, prep["digest_to_sign_hex"]), "p", "--trust", pem)
    assert code == 0, res
    assert res["validation"]["signatures"][0]["valid"] is True and res["trusted"] is True


def test_subprocess_contract_one_json_line(tmp_path, signer):
    src = make_pdf(tmp_path / "base.pdf", [{}])
    proc = run_subprocess("prepare-external", "--in", src, "--out", tmp_path / "p.pdf", "--state-out", tmp_path / "s.json",
                          "--cert", signer["cert_pem"], "--chain", signer["ca"], "--field-name", "AV_Externo_1")
    assert proc.returncode == 0, proc.stderr
    lines = [line for line in proc.stdout.decode("utf-8").splitlines() if line.strip()]
    assert len(lines) == 1 and json.loads(lines[0])["ok"] is True
