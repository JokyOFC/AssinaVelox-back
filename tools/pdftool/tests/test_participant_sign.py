"""K-A1: ``participant-sign`` — incremental PAdES B-B with the participant's own A1."""

import json

import pytest

from conftest import make_pdf, run_subprocess

PASS_A = "senha-participante-A-11!"
PASS_B = "senha-participante-B-22!"


@pytest.fixture
def participants(tmp_path, run_cli, monkeypatch):
    monkeypatch.setenv("PASS_A", PASS_A)
    monkeypatch.setenv("PASS_B", PASS_B)
    out = {}
    for label, env, name, cpf in (("a", "PASS_A", "Maria Alves", "52998224725"), ("b", "PASS_B", "Joao Lima", None)):
        pfx, ca = tmp_path / f"{label}.pfx", tmp_path / f"{label}-ca.pem"
        extra = ["--cpf", cpf] if cpf else []
        code, res = run_cli("gen-test-participant-cert", "--out-pfx", pfx, "--pass-env", env, "--name", name, "--out-ca-pem", ca, *extra)
        assert code == 0, res
        out[label] = {"pfx": pfx, "ca": ca, "env": env, "fingerprint": res["cert_fingerprint_sha256"]}
    return out


def _psign(run_cli, src, out, who, field, *extra):
    return run_cli("participant-sign", "--in", src, "--out", out, "--pfx", who["pfx"], "--pass-env", who["env"],
                   "--field-name", field, *extra)


def test_two_participants_in_sequence_preserve_the_first(tmp_path, run_cli, participants):
    a, b = participants["a"], participants["b"]
    src = make_pdf(tmp_path / "base.pdf", [{}, {"rotate": 90}])
    one, two = tmp_path / "one.pdf", tmp_path / "two.pdf"

    code, first = _psign(run_cli, src, one, a, "AV_Participante_A", "--expect-fingerprint", a["fingerprint"])
    assert code == 0, first
    assert first["profile"] == "PAdES-B-B" and first["timestamp"] is None
    assert first["previous_signature_count"] == 0 and first["signature_count"] == 1
    assert first["prefix_preserved"] is True and first["chain"]["ok"] is True
    assert first["cert_fingerprint_sha256"] == a["fingerprint"]
    assert first["certificate"]["holder"]["cpf_masked"] == "***.982.247-**"
    assert PASS_A not in json.dumps(first)
    assert one.read_bytes().startswith(src.read_bytes())

    code, second = _psign(run_cli, one, two, b, "AV_Participante_B", "--trust", a["ca"], "--trust", b["ca"])
    assert code == 0, second
    assert second["previous_signature_count"] == 1 and second["signature_count"] == 2
    # Incremental update: revision 1 is a byte-for-byte prefix of revision 2.
    assert two.read_bytes().startswith(one.read_bytes())

    validation = second["validation"]
    names = [s["field_name"] for s in validation["signatures"]]
    assert names == ["AV_Participante_A", "AV_Participante_B"]
    first_sig, second_sig = validation["signatures"]
    assert first_sig["intact"] and first_sig["valid"] and first_sig["trusted"]
    assert second_sig["intact"] and second_sig["valid"] and second_sig["trusted"]
    assert first_sig["coverage"] == "ENTIRE_REVISION" and first_sig["modification_level"] in ("NONE", "FORM_FILLING")
    assert second_sig["coverage"] == "ENTIRE_FILE"
    assert second["chain"] == {
        "ok": True,
        "signature_count": 2,
        "newest_covers_entire_file": True,
        "earlier_changes_permitted": True,
        "problems": [],
    }


def test_operator_signs_last_and_the_whole_chain_stays_sound(tmp_path, run_cli, participants, test_cert):
    from pdftool.participant_sign import analyse_chain

    a, b = participants["a"], participants["b"]
    op_pfx, op_pem, op_env = test_cert
    src = make_pdf(tmp_path / "base.pdf", [{}])
    one, two, final = tmp_path / "one.pdf", tmp_path / "two.pdf", tmp_path / "final.pdf"
    assert _psign(run_cli, src, one, a, "AV_P1")[0] == 0
    assert _psign(run_cli, one, two, b, "AV_P2")[0] == 0
    code, res = run_cli("sign", "--in", two, "--out", final, "--pfx", op_pfx, "--pass-env", op_env)
    assert code == 0, res
    assert final.read_bytes().startswith(two.read_bytes())

    code, val = run_cli("validate", "--in", final, "--trust", a["ca"], "--trust", b["ca"], "--trust", op_pem)
    assert code == 0, val
    assert val["signature_count"] == 3 and val["all_intact"] and val["all_valid"]
    assert [s["field_name"] for s in val["signatures"]] == ["AV_P1", "AV_P2", "AssinaVelox"]
    assert all(s["trusted"] for s in val["signatures"])
    # all_covering is false by design (older signatures cover their own revision);
    # the chain analysis is what says the incremental sequence is sound.
    assert val["all_covering"] is False
    chain = analyse_chain(val)
    assert chain["ok"] is True, chain


def test_tampering_one_byte_afterwards_breaks_validation(tmp_path, run_cli, participants):
    from pdftool.participant_sign import analyse_chain

    a, b = participants["a"], participants["b"]
    src = make_pdf(tmp_path / "base.pdf", [{"text": "Valor: R$ 100,00"}])
    one, two = tmp_path / "one.pdf", tmp_path / "two.pdf"
    assert _psign(run_cli, src, one, a, "AV_P1")[0] == 0
    assert _psign(run_cli, one, two, b, "AV_P2")[0] == 0

    data = two.read_bytes()
    assert b"Helvetica" in data
    tampered = tmp_path / "tampered.pdf"
    tampered.write_bytes(data.replace(b"Helvetica", b"Helvetics", 1))  # inside BOTH signed ranges
    code, val = run_cli("validate", "--in", tampered)
    assert code == 0, val
    assert val["all_intact"] is False
    assert all(s["intact"] is False for s in val["signatures"])
    assert analyse_chain(val)["ok"] is False

    # And nobody can sign on top of the broken file.
    code, res = _psign(run_cli, tampered, tmp_path / "three.pdf", a, "AV_P3")
    assert code == 4 and res["error"]["code"] == "previous_signature_invalid"
    assert not (tmp_path / "three.pdf").exists()


def test_wrong_passphrase_fails_without_leaking_and_writes_nothing(tmp_path, run_cli, participants, monkeypatch):
    a = participants["a"]
    src = make_pdf(tmp_path / "base.pdf", [{}])
    out = tmp_path / "out.pdf"
    monkeypatch.setenv("WRONG_A", "senha-errada-do-A-000")
    code, res = run_cli("participant-sign", "--in", src, "--out", out, "--pfx", a["pfx"], "--pass-env", "WRONG_A",
                        "--field-name", "AV_P1")
    assert code == 4 and res["error"]["code"] == "wrong_passphrase"
    assert "senha-errada-do-A-000" not in json.dumps(res)
    assert not out.exists()

    proc = run_subprocess("participant-sign", "--in", src, "--out", out, "--pfx", a["pfx"], "--pass-env", "W",
                          "--field-name", "AV_P1", env={"W": "senha-errada-do-A-000"})
    assert proc.returncode == 4
    assert b"senha-errada-do-A-000" not in proc.stdout + proc.stderr
    assert not out.exists()


def test_field_name_must_be_unique(tmp_path, run_cli, participants):
    a, b = participants["a"], participants["b"]
    src = make_pdf(tmp_path / "base.pdf", [{}])
    one = tmp_path / "one.pdf"
    assert _psign(run_cli, src, one, a, "AV_P1")[0] == 0
    code, res = _psign(run_cli, one, tmp_path / "two.pdf", b, "AV_P1")
    assert code == 2 and res["error"]["code"] == "field_name_taken"
    code, res = _psign(run_cli, one, tmp_path / "two.pdf", b, "nome com espaco")
    assert code == 2 and res["error"]["code"] == "invalid_field_name"


def test_fingerprint_mismatch_is_refused(tmp_path, run_cli, participants):
    a, b = participants["a"], participants["b"]
    src = make_pdf(tmp_path / "base.pdf", [{}])
    code, res = _psign(run_cli, src, tmp_path / "o.pdf", a, "AV_P1", "--expect-fingerprint", b["fingerprint"])
    assert code == 2 and res["error"]["code"] == "fingerprint_mismatch"
    assert not (tmp_path / "o.pdf").exists()


def test_expired_certificate_cannot_sign(tmp_path, run_cli, monkeypatch):
    monkeypatch.setenv("OLD_PASS", "velha")
    pfx = tmp_path / "old.pfx"
    assert run_cli("gen-test-participant-cert", "--out-pfx", pfx, "--pass-env", "OLD_PASS", "--valid-from-days", "-90", "--days", "30")[0] == 0
    src = make_pdf(tmp_path / "base.pdf", [{}])
    out = tmp_path / "o.pdf"
    code, res = run_cli("participant-sign", "--in", src, "--out", out, "--pfx", pfx, "--pass-env", "OLD_PASS", "--field-name", "AV_P1")
    assert code == 4 and res["error"]["code"] == "certificate_expired"
    assert not out.exists()


def test_analyse_chain_flags_uncovered_newest_signature():
    from pdftool.participant_sign import analyse_chain

    sig = {"field_name": "X", "intact": True, "valid": True, "docmdp_ok": None, "coverage": "ENTIRE_REVISION",
           "modification_level": "NONE", "errors": []}
    res = analyse_chain({"signatures": [sig]})
    assert res["ok"] is False and "newest_signature_does_not_cover_entire_file" in res["problems"][0]
    assert analyse_chain({"signatures": []})["ok"] is False
