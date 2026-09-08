import json

import pytest
from pypdf import PdfReader

from conftest import encrypt_pdf, make_pdf, run_subprocess


def _sign(run_cli, src, out, pfx, env, *extra):
    return run_cli("sign", "--in", src, "--out", out, "--pfx", pfx, "--pass-env", env, *extra)


def test_sign_and_validate_roundtrip(tmp_path, run_cli, test_cert):
    pfx, pem, env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{}, {"rotate": 90}])
    signed = tmp_path / "signed.pdf"
    code, res = _sign(run_cli, src, signed, pfx, env, "--reason", "Aceite", "--location", "São Paulo", "--contact", "ti@example.test")
    assert code == 0, res
    assert res["ok"] and res["profile"] == "PAdES-B-B"
    assert res["field_name"] == "AssinaVelox"
    assert res["signer_subject"] == "CN=AssinaVelox TESTE,O=AssinaVelox,C=BR"
    assert res["issuer"] == res["signer_subject"]
    assert res["md_algorithm"] == "sha256" and res["timestamp"] is None
    assert len(res["cert_fingerprint_sha256"]) == 64 and int(res["serial_hex"], 16) > 0
    assert res["not_before"] < res["not_after"]
    assert "s3cret" not in json.dumps(res)

    # Without trust roots: integrity verified, trust explicitly not claimed.
    code, val = run_cli("validate", "--in", signed)
    assert code == 0, val
    assert val["ok"] and val["signature_count"] == 1 and val["all_intact"] is True
    assert val["revocation"] == "not_checked"
    sig = val["signatures"][0]
    assert sig["field_name"] == "AssinaVelox"
    assert sig["intact"] is True and sig["valid"] is True
    assert sig["trusted"] is False and sig["trust_reason"] == "no_trust_roots_configured"
    assert sig["revocation"] == "not_checked"
    assert sig["subfilter"] == "/ETSI.CAdES.detached"
    assert sig["coverage"] == "ENTIRE_FILE" and sig["modification_level"] == "NONE"
    assert sig["md_algorithm"] == "sha256"
    assert sig["signing_time"] is not None
    assert sig["signer_subject"] == res["signer_subject"] and sig["serial_hex"] == res["serial_hex"]
    assert sig["summary"].startswith("INTACT:")
    assert sig["errors"] == []

    # With the self-signed certificate configured as trust root: trusted.
    code, val = run_cli("validate", "--in", signed, "--trust", pem, "--no-revocation")
    assert code == 0, val
    sig = val["signatures"][0]
    assert sig["intact"] and sig["valid"] and sig["trusted"] is True
    assert sig["trust_reason"] is None and sig["errors"] == []
    assert val["trust_roots_configured"] == 1


def test_validate_with_unrelated_trust_root_is_untrusted(tmp_path, run_cli, test_cert, monkeypatch):
    from pdftool.certs import generate_test_cert

    pfx, _pem, env = test_cert
    monkeypatch.setenv("OTHER_PASS", "other")
    other_pem = tmp_path / "other.pem"
    generate_test_cert(tmp_path / "other.pfx", "OTHER_PASS", subject="CN=Outro TESTE,O=X,C=BR", days=10, out_pem=other_pem)

    src = make_pdf(tmp_path / "src.pdf", [{}])
    signed = tmp_path / "signed.pdf"
    assert _sign(run_cli, src, signed, pfx, env)[0] == 0
    code, val = run_cli("validate", "--in", signed, "--trust", other_pem)
    assert code == 0
    sig = val["signatures"][0]
    assert sig["intact"] is True and sig["valid"] is True
    assert sig["trusted"] is False
    assert sig["trust_reason"] not in (None, "no_trust_roots_configured")
    assert any(e.startswith("trust:") for e in sig["errors"])


def test_sign_twice_preserves_first_signature(tmp_path, run_cli, test_cert):
    pfx, pem, env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{}])
    once, twice = tmp_path / "once.pdf", tmp_path / "twice.pdf"
    code, first = _sign(run_cli, src, once, pfx, env)
    assert code == 0 and first["field_name"] == "AssinaVelox"
    code, second = _sign(run_cli, once, twice, pfx, env)
    assert code == 0, second
    assert second["field_name"] == "AssinaVelox_2"
    # Incremental update: the first revision's bytes are a prefix of the new file.
    assert twice.read_bytes().startswith(once.read_bytes())

    code, val = run_cli("validate", "--in", twice, "--trust", pem)
    assert code == 0, val
    assert val["signature_count"] == 2 and val["all_intact"] is True
    names = [s["field_name"] for s in val["signatures"]]
    assert names == ["AssinaVelox", "AssinaVelox_2"]
    for sig in val["signatures"]:
        assert sig["intact"] and sig["valid"] and sig["trusted"]
    assert val["signatures"][0]["coverage"] == "ENTIRE_REVISION"
    assert val["signatures"][0]["modification_level"] in ("FORM_FILLING", "NONE")
    assert val["signatures"][1]["coverage"] == "ENTIRE_FILE"

    code, info = run_cli("inspect", "--in", twice)
    assert info["signature_count"] == 2 and info["signature_fields"] == names


def test_explicit_field_name(tmp_path, run_cli, test_cert):
    pfx, _pem, env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{}])
    code, res = _sign(run_cli, src, tmp_path / "s.pdf", pfx, env, "--field-name", "Signatario1")
    assert code == 0 and res["field_name"] == "Signatario1"


def test_visible_signature_on_rotated_page(tmp_path, run_cli, test_cert):
    pfx, pem, env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{}, {"rotate": 90}])
    signed = tmp_path / "signed.pdf"
    code, res = _sign(run_cli, src, signed, pfx, env, "--visible", "2,0.55,0.85,0.40,0.10")
    assert code == 0, res
    assert res["visible"] is True

    reader = PdfReader(str(signed))
    page = reader.pages[1]
    annots = [a.get_object() for a in page["/Annots"]]
    widgets = [a for a in annots if a.get("/Subtype") == "/Widget" and a.get("/FT") == "/Sig"]
    assert len(widgets) == 1
    rect = [float(v) for v in widgets[0]["/Rect"]]
    # Displayed 841.89 x 595.28; x=0.55..0.95 -> user y 463..800; y=0.85..0.95 -> user x 506..565
    assert rect[0] >= 505 and rect[2] <= 566 and rect[1] >= 462 and rect[3] <= 801
    appearance = widgets[0]["/AP"]["/N"].get_object()
    assert [float(v) for v in appearance["/Matrix"]] == [0, 1, -1, 0, 0, 0]  # upright on a /Rotate 90 page
    bbox = [float(v) for v in appearance["/BBox"]]
    assert bbox[2] > bbox[3]  # laid out in displayed (landscape) orientation
    reader.stream.close()

    code, val = run_cli("validate", "--in", signed, "--trust", pem)
    assert code == 0 and val["signatures"][0]["intact"] and val["signatures"][0]["trusted"]


def test_visible_signature_unrotated_page_has_no_matrix(tmp_path, run_cli, test_cert):
    pfx, _pem, env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{}])
    signed = tmp_path / "signed.pdf"
    code, res = _sign(run_cli, src, signed, pfx, env, "--visible", "1,0.1,0.1,0.4,0.08")
    assert code == 0, res
    reader = PdfReader(str(signed))
    widget = [a.get_object() for a in reader.pages[0]["/Annots"]][0]
    assert "/Matrix" not in widget["/AP"]["/N"].get_object()
    reader.stream.close()


def test_visible_bad_spec_exit_2(tmp_path, run_cli, test_cert):
    pfx, _pem, env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{}])
    code, res = _sign(run_cli, src, tmp_path / "s.pdf", pfx, env, "--visible", "1,0.5,0.5")
    assert code == 2 and res["error"]["code"] == "invalid_visible"
    code, res = _sign(run_cli, src, tmp_path / "s.pdf", pfx, env, "--visible", "9,0.1,0.1,0.2,0.1")
    assert code == 2 and res["error"]["code"] == "invalid_visible"


def test_missing_passphrase_exit_2(tmp_path, run_cli, test_cert, monkeypatch):
    pfx, _pem, _env = test_cert
    monkeypatch.delenv("PDFTOOL_UNSET_VAR", raising=False)
    src = make_pdf(tmp_path / "src.pdf", [{}])
    out = tmp_path / "s.pdf"
    code, res = _sign(run_cli, src, out, pfx, "PDFTOOL_UNSET_VAR")
    assert code == 2 and res["error"]["code"] == "missing_passphrase"
    assert not out.exists()


def test_missing_passphrase_subprocess(tmp_path, test_cert):
    pfx, _pem, _env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{}])
    env = {"PDFTOOL_UNSET_VAR": ""}  # empty counts as unset
    proc = run_subprocess("sign", "--in", src, "--out", tmp_path / "s.pdf", "--pfx", pfx, "--pass-env", "PDFTOOL_UNSET_VAR", env=env)
    assert proc.returncode == 2
    assert json.loads(proc.stdout.decode("utf-8"))["error"]["code"] == "missing_passphrase"


def test_wrong_passphrase_exit_4(tmp_path, run_cli, test_cert, monkeypatch):
    pfx, _pem, _env = test_cert
    monkeypatch.setenv("WRONG_PASS", "definitely-wrong")
    src = make_pdf(tmp_path / "src.pdf", [{}])
    code, res = _sign(run_cli, src, tmp_path / "s.pdf", pfx, "WRONG_PASS")
    assert code == 4 and res["error"]["code"] == "pfx_load_failed"
    assert "definitely-wrong" not in json.dumps(res)


def test_corrupt_input_exit_4(tmp_path, run_cli, test_cert):
    pfx, _pem, env = test_cert
    bad = tmp_path / "bad.pdf"
    bad.write_bytes(b"%PDF-1.7 not really")
    code, res = _sign(run_cli, bad, tmp_path / "s.pdf", pfx, env)
    assert code == 4 and res["error"]["code"] == "invalid_pdf"


def test_encrypted_input_rejected_for_signing(tmp_path, run_cli, test_cert):
    pfx, _pem, env = test_cert
    plain = make_pdf(tmp_path / "plain.pdf", [{}])
    enc = encrypt_pdf(plain, tmp_path / "enc.pdf", user_password="", owner_password="owner")
    code, res = _sign(run_cli, enc, tmp_path / "s.pdf", pfx, env)
    assert code == 4 and res["error"]["code"] == "encrypted_pdf"


def test_tampered_document_is_not_intact(tmp_path, run_cli, test_cert):
    pfx, pem, env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{"text": "Valor: R$ 100,00"}])
    signed = tmp_path / "signed.pdf"
    assert _sign(run_cli, src, signed, pfx, env)[0] == 0
    data = signed.read_bytes()
    assert b"Helvetica" in data
    tampered = tmp_path / "tampered.pdf"
    tampered.write_bytes(data.replace(b"Helvetica", b"Helvetics", 1))  # byte inside the signed range
    code, val = run_cli("validate", "--in", tampered, "--trust", pem)
    assert code == 0, val
    assert val["all_intact"] is False
    sig = val["signatures"][0]
    assert sig["intact"] is False and sig["trusted"] is False
    assert sig["summary"] == "INVALID"
    assert any("digest_mismatch" in e for e in sig["errors"])


def test_validate_unsigned_pdf(tmp_path, run_cli):
    src = make_pdf(tmp_path / "src.pdf", [{}])
    code, val = run_cli("validate", "--in", src)
    assert code == 0
    assert val == {
        "ok": True,
        "signature_count": 0,
        "all_intact": False,
        "all_valid": False,
        "trust_roots_configured": 0,
        "revocation": "not_checked",
        "signatures": [],
    }


def test_validate_bad_trust_file(tmp_path, run_cli):
    src = make_pdf(tmp_path / "src.pdf", [{}])
    code, res = run_cli("validate", "--in", src, "--trust", tmp_path / "missing.pem")
    assert code == 2 and res["error"]["code"] == "trust_file_not_found"
    junk = tmp_path / "junk.pem"
    junk.write_bytes(b"-----BEGIN CERTIFICATE-----\nnotbase64!!\n-----END CERTIFICATE-----\n")
    code, res = run_cli("validate", "--in", src, "--trust", junk)
    assert code == 2 and res["error"]["code"] == "invalid_trust_file"


def test_gen_test_cert_forces_teste_marker(tmp_path, run_cli, monkeypatch):
    monkeypatch.setenv("GEN_PASS", "x")
    pfx = tmp_path / "c.pfx"
    code, res = run_cli("gen-test-cert", "--out-pfx", pfx, "--pass-env", "GEN_PASS", "--subject", "CN=Fulano de Tal,O=Empresa,C=BR", "--days", "10")
    assert code == 0, res
    assert res["subject"] == "CN=Fulano de Tal TESTE,O=Empresa,C=BR"
    assert res["self_signed"] is True and res["test_only"] is True
    assert pfx.is_file() and res["pem_path"] is None
    assert len(res["cert_fingerprint_sha256"]) == 64


def test_gen_test_cert_missing_passphrase(tmp_path, run_cli, monkeypatch):
    monkeypatch.delenv("GEN_PASS_MISSING", raising=False)
    code, res = run_cli("gen-test-cert", "--out-pfx", tmp_path / "c.pfx", "--pass-env", "GEN_PASS_MISSING")
    assert code == 2 and res["error"]["code"] == "missing_passphrase"


def test_gen_test_cert_bad_subject(tmp_path, run_cli, monkeypatch):
    monkeypatch.setenv("GEN_PASS", "x")
    code, res = run_cli("gen-test-cert", "--out-pfx", tmp_path / "c.pfx", "--pass-env", "GEN_PASS", "--subject", "not a dn")
    assert code == 2 and res["error"]["code"] == "invalid_subject"


def test_selftest(run_cli):
    code, res = run_cli("selftest")
    assert code == 0, res
    assert res["ok"] is True
    names = [s["step"] for s in res["steps"]]
    for required in ("generate_source_pdf", "compose", "append", "gen_test_cert", "sign", "validate_with_trust", "validate_without_trust"):
        assert required in names
    assert all(s["ok"] for s in res["steps"])
    assert res["temp_dir"] is None
