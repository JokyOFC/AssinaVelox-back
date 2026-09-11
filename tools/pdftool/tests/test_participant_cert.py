"""K-A1: ``inspect-cert`` and ``gen-test-participant-cert`` (participant's own A1)."""

import json

import pytest

from conftest import run_subprocess

PASS = "s3nha-do-participante-Q7!"
VALID_CPF = "52998224725"


@pytest.fixture
def pass_env(monkeypatch):
    monkeypatch.setenv("PARTICIPANT_PASS", PASS)
    return "PARTICIPANT_PASS"


def _gen(run_cli, tmp_path, pass_env, name="p.pfx", *extra):
    pfx = tmp_path / name
    code, res = run_cli("gen-test-participant-cert", "--out-pfx", pfx, "--pass-env", pass_env, *extra)
    assert code == 0, res
    return pfx, res


def test_inspect_reports_public_facts_only(tmp_path, run_cli, pass_env):
    pfx, gen = _gen(run_cli, tmp_path, pass_env, "p.pfx", "--name", "Maria Alves", "--cpf", VALID_CPF, "--out-ca-pem", tmp_path / "ca.pem")
    assert gen["test_only"] is True and "TESTE" in gen["subject"]

    code, res = run_cli("inspect-cert", "--pfx", pfx, "--pass-env", pass_env)
    assert code == 0, res
    assert res["ok"] is True
    assert res["subject_cn"].startswith("Maria Alves TESTE")
    assert res["issuer_cn"] == "AC TESTE AssinaVelox Participantes"
    assert res["cert_fingerprint_sha256"] == gen["cert_fingerprint_sha256"]
    assert res["serial_hex"] == gen["serial_hex"]
    assert res["key_matches_certificate"] is True and res["has_private_key"] is True
    assert "digital_signature" in res["key_usage"] and "non_repudiation" in res["key_usage"]
    assert {e["name"] for e in res["extended_key_usage"]} == {"client_auth", "email_protection"}
    assert res["chain_length"] == 1 and res["chain_reaches_self_signed_root"] is True
    assert res["chain"][0]["is_ca"] is True
    assert res["test_certificate"] is True and res["warnings"]
    # CPF: read from the ICP-Brasil otherName, never "confirmed".
    holder = res["holder"]
    assert holder["name"] == "Maria Alves TESTE" or holder["name"].startswith("Maria Alves")
    assert holder["cpf"] == VALID_CPF
    assert holder["cpf_masked"] == "***.982.247-**"
    assert holder["cpf_source"] == "subject_alt_name.other_name.2.16.76.1.3.1"
    assert holder["cpf_check_digits_valid"] is True
    assert holder["cpf_confirmed"] is False
    assert res["icp_brasil"]["declares_icp_brasil_policy"] is False
    assert res["icp_brasil"]["chain_validated"] is False
    # No key material and no passphrase anywhere in the output.
    dumped = json.dumps(res)
    assert PASS not in dumped
    assert "PRIVATE KEY" not in dumped
    assert not any(key in res for key in ("key", "private_key", "pfx", "pfx_bytes"))


def test_inspect_without_cpf(tmp_path, run_cli, pass_env):
    pfx, _ = _gen(run_cli, tmp_path, pass_env, "p.pfx", "--name", "Joao Lima", "--self-signed")
    code, res = run_cli("inspect-cert", "--pfx", pfx, "--pass-env", pass_env)
    assert code == 0, res
    assert res["self_signed"] is True and res["chain_length"] == 0
    assert res["holder"]["cpf"] is None and res["holder"]["cpf_source"] is None


def test_wrong_passphrase_is_refused_without_leaking(tmp_path, run_cli, pass_env, monkeypatch):
    pfx, _ = _gen(run_cli, tmp_path, pass_env)
    monkeypatch.setenv("WRONG_PARTICIPANT_PASS", "senha-errada-XYZ-123")
    code, res = run_cli("inspect-cert", "--pfx", pfx, "--pass-env", "WRONG_PARTICIPANT_PASS")
    assert code == 4 and res["error"]["code"] == "wrong_passphrase"
    assert "senha-errada-XYZ-123" not in json.dumps(res)


def test_wrong_passphrase_subprocess_never_prints_secret(tmp_path, run_cli, pass_env):
    pfx, _ = _gen(run_cli, tmp_path, pass_env)
    secret = "outra-senha-errada-987"
    proc = run_subprocess("inspect-cert", "--pfx", pfx, "--pass-env", "X_PASS", env={"X_PASS": secret})
    assert proc.returncode == 4
    assert json.loads(proc.stdout.decode("utf-8"))["error"]["code"] == "wrong_passphrase"
    assert secret.encode() not in proc.stdout and secret.encode() not in proc.stderr
    # The right passphrase is never printed either.
    proc = run_subprocess("inspect-cert", "--pfx", pfx, "--pass-env", "X_PASS", env={"X_PASS": PASS})
    assert proc.returncode == 0
    assert PASS.encode() not in proc.stdout and PASS.encode() not in proc.stderr


def test_corrupt_file_is_refused(tmp_path, run_cli, pass_env):
    bad = tmp_path / "bad.pfx"
    bad.write_bytes(b"isto nao e um pkcs12" * 10)
    code, res = run_cli("inspect-cert", "--pfx", bad, "--pass-env", pass_env)
    assert code == 4 and res["error"]["code"] == "invalid_pkcs12"


def test_truncated_file_is_refused(tmp_path, run_cli, pass_env):
    pfx, _ = _gen(run_cli, tmp_path, pass_env)
    cut = tmp_path / "cut.pfx"
    cut.write_bytes(pfx.read_bytes()[:200])
    code, res = run_cli("inspect-cert", "--pfx", cut, "--pass-env", pass_env)
    assert code == 4 and res["error"]["code"] == "invalid_pkcs12"


def test_pkcs12_without_key_is_refused(tmp_path, run_cli, pass_env):
    pfx, gen = _gen(run_cli, tmp_path, pass_env, "nokey.pfx", "--no-key")
    assert gen["has_private_key"] is False
    code, res = run_cli("inspect-cert", "--pfx", pfx, "--pass-env", pass_env)
    assert code == 4 and res["error"]["code"] == "pkcs12_without_key"


def test_expired_certificate_is_refused(tmp_path, run_cli, pass_env):
    pfx, _ = _gen(run_cli, tmp_path, pass_env, "old.pfx", "--valid-from-days", "-60", "--days", "30")
    code, res = run_cli("inspect-cert", "--pfx", pfx, "--pass-env", pass_env)
    assert code == 4 and res["error"]["code"] == "certificate_expired"


def test_not_yet_valid_certificate_is_refused(tmp_path, run_cli, pass_env):
    pfx, _ = _gen(run_cli, tmp_path, pass_env, "future.pfx", "--valid-from-days", "10")
    code, res = run_cli("inspect-cert", "--pfx", pfx, "--pass-env", pass_env)
    assert code == 4 and res["error"]["code"] == "certificate_not_yet_valid"


def test_certificate_without_signing_usage_is_refused(tmp_path, run_cli, pass_env):
    pfx, _ = _gen(run_cli, tmp_path, pass_env, "enc.pfx", "--key-usage", "encipherment")
    code, res = run_cli("inspect-cert", "--pfx", pfx, "--pass-env", pass_env)
    assert code == 4 and res["error"]["code"] == "certificate_not_for_signing"


def test_missing_passphrase_variable(tmp_path, run_cli, pass_env, monkeypatch):
    pfx, _ = _gen(run_cli, tmp_path, pass_env)
    monkeypatch.delenv("UNSET_PARTICIPANT_PASS", raising=False)
    code, res = run_cli("inspect-cert", "--pfx", pfx, "--pass-env", "UNSET_PARTICIPANT_PASS")
    assert code == 2 and res["error"]["code"] == "missing_passphrase"


def test_missing_file(tmp_path, run_cli, pass_env):
    code, res = run_cli("inspect-cert", "--pfx", tmp_path / "nada.pfx", "--pass-env", pass_env)
    assert code == 4 and res["error"]["code"] == "missing_input"


def test_cpf_check_digits_and_mask():
    from pdftool.inspect_cert import cpf_check_digits_valid, mask_cpf

    assert cpf_check_digits_valid(VALID_CPF) is True
    assert cpf_check_digits_valid("52998224726") is False
    assert cpf_check_digits_valid("11111111111") is False
    assert mask_cpf(VALID_CPF) == "***.982.247-**"
    assert mask_cpf(None) is None
