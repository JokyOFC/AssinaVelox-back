import json

import pytest

from conftest import A4_H, A4_W, encrypt_pdf, make_pdf, run_subprocess


def test_inspect_normal(tmp_path, run_cli):
    pdf = make_pdf(tmp_path / "a.pdf", [{}, {}])
    code, out = run_cli("inspect", "--in", pdf)
    assert code == 0
    assert out["ok"] is True
    assert out["page_count"] == 2
    assert out["encrypted"] is False and out["openable"] is True
    assert out["has_signatures"] is False and out["signature_count"] == 0 and out["signature_fields"] == []
    assert out["has_acroform"] is False and out["has_xfa"] is False
    assert out["pdf_version"].startswith("1.")
    assert set(out["metadata"]) == {"title", "author", "producer", "creator"}
    assert "permissions" not in out
    page = out["pages"][0]
    assert page["index"] == 1 and page["rotation"] == 0
    assert page["width_pt"] == pytest.approx(A4_W, abs=0.01)
    assert page["height_pt"] == pytest.approx(A4_H, abs=0.01)
    assert page["mediabox"] == pytest.approx([0, 0, A4_W, A4_H], abs=0.01)
    assert page["cropbox"] == page["mediabox"]


def test_inspect_rotated_and_cropped(tmp_path, run_cli):
    pdf = make_pdf(
        tmp_path / "r.pdf",
        [{"rotate": 90}, {"rotate": 270, "cropbox": [50, 100, 450, 700]}, {"rotate": 180}],
    )
    code, out = run_cli("inspect", "--in", pdf)
    assert code == 0
    p1, p2, p3 = out["pages"]
    assert p1["rotation"] == 90
    assert p1["width_pt"] == pytest.approx(A4_H, abs=0.01)  # swapped
    assert p1["height_pt"] == pytest.approx(A4_W, abs=0.01)
    assert p2["rotation"] == 270
    assert p2["cropbox"] == [50, 100, 450, 700]
    assert p2["width_pt"] == 600 and p2["height_pt"] == 400  # crop 400x600 displayed rotated
    assert p3["rotation"] == 180
    assert p3["width_pt"] == pytest.approx(A4_W, abs=0.01)


def test_inspect_encrypted_owner_password_only(tmp_path, run_cli):
    plain = make_pdf(tmp_path / "plain.pdf", [{}])
    enc = encrypt_pdf(plain, tmp_path / "enc.pdf", user_password="", owner_password="owner-secret")
    code, out = run_cli("inspect", "--in", enc)
    assert code == 0
    assert out["ok"] is True
    assert out["encrypted"] is True and out["openable"] is True
    assert out["page_count"] == 1
    assert isinstance(out["permissions"], list)


def test_inspect_encrypted_user_password_rejected(tmp_path, run_cli):
    plain = make_pdf(tmp_path / "plain.pdf", [{}])
    enc = encrypt_pdf(plain, tmp_path / "enc.pdf", user_password="user-secret", owner_password="owner-secret")
    code, out = run_cli("inspect", "--in", enc)
    assert code == 4
    assert out["ok"] is False and out["error"]["code"] == "encrypted_pdf"
    assert "user-secret" not in json.dumps(out)


@pytest.mark.parametrize("payload", [b"%PDF-1.4\n garbage without xref or trailer", b"this is not a pdf at all", b""])
def test_inspect_corrupt(tmp_path, run_cli, payload):
    bad = tmp_path / "bad.pdf"
    bad.write_bytes(payload)
    code, out = run_cli("inspect", "--in", bad)
    assert code == 4
    assert out["ok"] is False and out["error"]["code"] == "invalid_pdf"


def test_inspect_missing_file(tmp_path, run_cli):
    code, out = run_cli("inspect", "--in", tmp_path / "nope.pdf")
    assert code == 4 and out["error"]["code"] == "missing_input"


def test_inspect_signed(tmp_path, run_cli, test_cert):
    pfx, _pem, env = test_cert
    src = make_pdf(tmp_path / "src.pdf", [{}])
    signed = tmp_path / "signed.pdf"
    code, out = run_cli("sign", "--in", src, "--out", signed, "--pfx", pfx, "--pass-env", env)
    assert code == 0, out
    code, out = run_cli("inspect", "--in", signed)
    assert code == 0
    assert out["has_signatures"] is True
    assert out["signature_count"] == 1
    assert out["signature_fields"] == ["AssinaVelox"]
    assert out["has_acroform"] is True


def test_usage_errors_exit_2(run_cli):
    code, out = run_cli("inspect")
    assert code == 2 and out["ok"] is False and out["error"]["code"] == "usage_error"
    code, out = run_cli("no-such-command")
    assert code == 2 and out["error"]["code"] == "usage_error"


def test_subprocess_contract(tmp_path):
    """The real invocation path Laravel uses: one JSON line on stdout, exit code 0."""
    pdf = make_pdf(tmp_path / "a.pdf", [{"text": "Acentuação: ção, ã, é"}])
    proc = run_subprocess("inspect", "--in", pdf)
    assert proc.returncode == 0, proc.stderr.decode("utf-8", "replace")
    stdout = proc.stdout.decode("utf-8")
    assert stdout.count("\n") == 1 and stdout.endswith("\n")
    parsed = json.loads(stdout)
    assert parsed["ok"] is True and parsed["page_count"] == 1


def test_subprocess_error_exit_code(tmp_path):
    bad = tmp_path / "bad.pdf"
    bad.write_bytes(b"nope")
    proc = run_subprocess("inspect", "--in", bad)
    assert proc.returncode == 4
    parsed = json.loads(proc.stdout.decode("utf-8"))
    assert parsed == {"ok": False, "error": {"code": "invalid_pdf", "message": parsed["error"]["message"]}}
    proc = run_subprocess("inspect")
    assert proc.returncode == 2
    assert json.loads(proc.stdout.decode("utf-8"))["error"]["code"] == "usage_error"
