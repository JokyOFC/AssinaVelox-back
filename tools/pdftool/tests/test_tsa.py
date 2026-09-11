"""Operator TSA (RFC 3161): tsa-gen-test, tsa-issue, tsa-verify, serials. Not ICP-Brasil."""

from __future__ import annotations

import concurrent.futures
import hashlib
import shutil
import subprocess
from pathlib import Path

import pytest
from asn1crypto import cms, tsp
from cryptography import x509
from cryptography.hazmat.primitives.serialization import pkcs12
from cryptography.x509.oid import ExtendedKeyUsageOID, ExtensionOID

from conftest import run_subprocess
from pdftool.tsa import TimeStampResponse

POLICY = "2.25.329800735698586629295641978511506172918"
PASS_ENV = "PDFTOOL_TSA_TEST_PASS"
PASSPHRASE = "tsa-s3cret-de-teste"


@pytest.fixture(scope="module")
def tsa(tmp_path_factory):
    """One EC P-256 test TSA per module (fast); returns a dict of paths."""
    import os

    from pdftool.tsa import generate_test_tsa

    base = tmp_path_factory.mktemp("tsa")
    os.environ[PASS_ENV] = PASSPHRASE
    result = generate_test_tsa(base / "tsa.pfx", PASS_ENV, base / "root.pem", out_chain_pem=base / "chain.pem", days=30, key_type="ec-p256")
    other = generate_test_tsa(base / "other.pfx", PASS_ENV, base / "other-root.pem", days=30, key_type="ec-p256")
    return {"pfx": base / "tsa.pfx", "root": base / "root.pem", "chain": base / "chain.pem", "other_root": base / "other-root.pem", "meta": result, "other": other}


@pytest.fixture(autouse=True)
def _passphrase(monkeypatch):
    monkeypatch.setenv(PASS_ENV, PASSPHRASE)


def _digest(data: bytes, algorithm: str = "sha256") -> str:
    return hashlib.new(algorithm, data).hexdigest()


def _issue(run_cli, tsa, tmp_path, digest: str, serial: int = 1, *extra):
    out = tmp_path / f"t{serial}.tsr"
    code, payload = run_cli(
        "tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", PASS_ENV, "--policy-oid", POLICY,
        "--serial", serial, "--digest", digest, "--out", out, *extra,
    )
    return code, payload, out


# ------------------------------------------------------------------ gen-test


def test_gen_test_tsa_has_critical_timestamping_eku_and_test_marker(tsa):
    key, cert, extra = pkcs12.load_key_and_certificates(tsa["pfx"].read_bytes(), PASSPHRASE.encode())
    eku = cert.extensions.get_extension_for_oid(ExtensionOID.EXTENDED_KEY_USAGE)
    assert eku.critical is True
    assert list(eku.value) == [ExtendedKeyUsageOID.TIME_STAMPING]
    assert "TESTE" in cert.subject.rfc4514_string()
    assert len(extra) == 1 and "TESTE" in extra[0].subject.rfc4514_string()
    assert extra[0].extensions.get_extension_for_class(x509.BasicConstraints).value.ca is True
    assert tsa["meta"]["test_only"] is True
    assert "ICP-Brasil" in tsa["meta"]["warning"] and "Nao e ACT" in tsa["meta"]["warning"]


def test_gen_test_forces_teste_in_the_common_name(tmp_path):
    from pdftool.tsa import generate_test_tsa

    result = generate_test_tsa(tmp_path / "x.pfx", PASS_ENV, tmp_path / "x.pem", subject="CN=Carimbo Oficial,O=Qualquer,C=BR", key_type="ec-p256")
    assert "TESTE" in result["tsa_subject"]


# ------------------------------------------------------------------ issue + verify


def test_issued_token_validates_against_the_same_digest_and_the_tsa_root(run_cli, tsa, tmp_path):
    data = b"manifesto do dossie"
    code, issued, out = _issue(run_cli, tsa, tmp_path, _digest(data), 41, "--nonce", "777")
    assert code == 0 and issued["status"] == "granted"
    assert issued["serial"] == "41"
    assert issued["policy_oid"] == POLICY
    assert issued["tsa_kind"] == "operator"
    assert "não é carimbo ICP-Brasil" in issued["label"]
    assert issued["tsa_certificate_test"] is True
    assert issued["nonce"] == "777"

    (tmp_path / "data.bin").write_bytes(data)
    code, verified = run_cli("tsa-verify", "--token", out, "--data", tmp_path / "data.bin", "--trust", tsa["root"], "--nonce", "777", "--policy-oid", POLICY)
    assert code == 0
    assert verified["valid"] is True, verified["errors"]
    assert verified["trusted"] is True
    assert verified["imprint_matches"] and verified["ess_cert_id_matches"] and verified["eku_ok"]
    assert verified["nonce_matches"] is True and verified["policy_matches"] is True
    assert verified["serial"] == "41"


def test_a_different_digest_does_not_validate(run_cli, tsa, tmp_path):
    code, _issued, out = _issue(run_cli, tsa, tmp_path, _digest(b"original"), 42)
    assert code == 0
    code, verified = run_cli("tsa-verify", "--token", out, "--digest", _digest(b"adulterado"), "--trust", tsa["root"])
    assert code == 0
    assert verified["valid"] is False
    assert verified["trusted"] is False
    assert verified["imprint_matches"] is False
    assert any(e.startswith("imprint_mismatch") for e in verified["errors"])


def test_tampered_token_is_invalid(run_cli, tsa, tmp_path):
    code, _issued, out = _issue(run_cli, tsa, tmp_path, _digest(b"x"), 43)
    resp = TimeStampResponse.load(out.read_bytes())
    token = resp["time_stamp_token"]
    tst = token["content"]["encap_content_info"]["content"].parsed
    forged = tsp.TSTInfo.load(tst.dump())
    forged["serial_number"] = 4300  # the signed TSTInfo changes, the signature does not
    sd = token["content"].copy()
    sd["encap_content_info"] = cms.EncapsulatedContentInfo({"content_type": "tst_info", "content": cms.ParsableOctetString(forged.dump())})
    tampered = tmp_path / "forged.tsr"
    tampered.write_bytes(TimeStampResponse({"status": resp["status"], "time_stamp_token": cms.ContentInfo({"content_type": "signed_data", "content": sd})}).dump())
    code, verified = run_cli("tsa-verify", "--token", tampered, "--digest", _digest(b"x"), "--trust", tsa["root"])
    assert code == 0
    assert verified["valid"] is False
    assert verified["signature_intact"] is False


def test_untrusted_root_is_reported_and_no_root_is_never_trusted(run_cli, tsa, tmp_path):
    code, _issued, out = _issue(run_cli, tsa, tmp_path, _digest(b"y"), 44)
    code, other = run_cli("tsa-verify", "--token", out, "--digest", _digest(b"y"), "--trust", tsa["other_root"])
    assert other["valid"] is True and other["trusted"] is False and other["trust_reason"]
    code, none = run_cli("tsa-verify", "--token", out, "--digest", _digest(b"y"))
    assert none["valid"] is True and none["trusted"] is False
    assert none["trust_reason"] == "no_trust_roots_configured"


@pytest.mark.skipif(shutil.which("openssl") is None, reason="openssl not on PATH")
def test_independent_openssl_ts_verify_accepts_the_token(run_cli, tsa, tmp_path):
    data = tmp_path / "manifest.json"
    data.write_bytes(b'{"format":"assinavelox-dossier/1"}')
    code, _issued, out = _issue(run_cli, tsa, tmp_path, _digest(data.read_bytes()), 45)
    ok = subprocess.run(["openssl", "ts", "-verify", "-data", str(data), "-in", str(out), "-CAfile", str(tsa["root"])], capture_output=True, text=True)
    assert "Verification: OK" in ok.stdout, ok.stderr
    data.write_bytes(b'{"format":"assinavelox-dossier/2"}')
    bad = subprocess.run(["openssl", "ts", "-verify", "-data", str(data), "-in", str(out), "-CAfile", str(tsa["root"])], capture_output=True, text=True)
    assert "Verification: OK" not in bad.stdout


def test_request_mode_echoes_nonce_and_honours_cert_req(run_cli, tsa, tmp_path):
    from pdftool.tsa import build_request

    digest = hashlib.sha512(b"z").digest()
    req_path = tmp_path / "q.tsq"
    req_path.write_bytes(build_request(digest, "sha512", nonce=123456789, cert_req=False).dump())
    out = tmp_path / "q.tsr"
    code, issued = run_cli("tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", PASS_ENV, "--policy-oid", POLICY, "--serial", 46, "--request", req_path, "--out", out)
    assert code == 0 and issued["status"] == "granted"
    assert issued["cert_req"] is False and issued["hash_algorithm"] == "sha512"
    token = TimeStampResponse.load(out.read_bytes())["time_stamp_token"]
    assert not token["content"]["certificates"].native  # certReq=false -> MUST NOT carry certs
    tst = token["content"]["encap_content_info"]["content"].parsed
    assert tst["nonce"].native == 123456789

    code, missing = run_cli("tsa-verify", "--token", out, "--digest", digest.hex(), "--trust", tsa["root"])
    assert missing["valid"] is False
    code, verified = run_cli("tsa-verify", "--token", out, "--digest", digest.hex(), "--trust", tsa["root"], "--tsa-cert", tsa["chain"], "--nonce", 123456789)
    assert verified["valid"] is True and verified["trusted"] is True, verified["errors"]


@pytest.mark.parametrize(
    "builder, fail_info",
    [
        (lambda b: b(hashlib.sha1(b"a").digest(), "sha1"), "bad_alg"),
        (lambda b: b(b"\x01" * 20, "sha256"), "bad_data_format"),
        (lambda b: b(hashlib.sha256(b"a").digest(), "sha256", policy_oid="1.2.3.4"), "unaccepted_policy"),
        (None, "bad_data_format"),
    ],
)
def test_rejections_are_rfc3161_responses_without_a_token(run_cli, tsa, tmp_path, builder, fail_info):
    from pdftool.tsa import build_request

    req_path = tmp_path / "bad.tsq"
    req_path.write_bytes(b"garbage, not DER" if builder is None else builder(build_request).dump())
    out = tmp_path / "bad.tsr"
    code, issued = run_cli("tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", PASS_ENV, "--policy-oid", POLICY, "--serial", 47, "--request", req_path, "--out", out)
    assert code == 0
    assert issued["status"] == "rejection" and issued["fail_info"] == fail_info
    assert issued["serial"] is None
    resp = TimeStampResponse.load(out.read_bytes())
    assert resp["status"]["status"].native == "rejection"
    assert fail_info in resp["status"]["fail_info"].native
    assert resp["time_stamp_token"].native is None


def test_request_extensions_are_refused(run_cli, tsa, tmp_path):
    from pdftool.tsa import build_request

    req = build_request(hashlib.sha256(b"e").digest(), "sha256")
    req["extensions"] = [{"extn_id": "1.2.3.4.5", "critical": False, "extn_value": b"\x05\x00"}]
    req_path = tmp_path / "ext.tsq"
    req_path.write_bytes(req.dump())
    code, issued = run_cli("tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", PASS_ENV, "--policy-oid", POLICY, "--serial", 48, "--request", req_path, "--out", tmp_path / "ext.tsr")
    assert issued["status"] == "rejection" and issued["fail_info"] == "unaccepted_extensions"


# ------------------------------------------------------------------ serials


def test_serial_is_exactly_the_one_given_and_must_be_positive(run_cli, tsa, tmp_path):
    code, issued, _ = _issue(run_cli, tsa, tmp_path, _digest(b"s"), 900001)
    assert issued["serial"] == "900001"
    code, payload, _ = _issue(run_cli, tsa, tmp_path, _digest(b"s"), 0)
    assert code == 2 and payload["error"]["code"] == "invalid_serial"
    code, payload = run_cli("tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", PASS_ENV, "--policy-oid", POLICY, "--digest", _digest(b"s"), "--out", tmp_path / "n.tsr")
    assert code == 2 and payload["error"]["code"] == "serial_required"


def _allocate(path: str) -> int:
    from pdftool.tsa import allocate_serial_from_file

    return allocate_serial_from_file(Path(path))


def test_serial_file_is_unique_under_concurrency(tmp_path):
    counter = tmp_path / "serial.txt"
    with concurrent.futures.ProcessPoolExecutor(max_workers=4) as pool:
        serials = list(pool.map(_allocate, [str(counter)] * 60))
    assert len(set(serials)) == 60
    assert sorted(serials) == list(range(1, 61))
    assert counter.read_text().strip() == "60"


def test_two_tokens_from_the_serial_file_never_share_a_serial(run_cli, tsa, tmp_path):
    counter = tmp_path / "c.txt"
    seen = set()
    for index in range(3):
        code, issued = run_cli("tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", PASS_ENV, "--policy-oid", POLICY, "--serial-file", counter, "--digest", _digest(bytes([index])), "--out", tmp_path / f"f{index}.tsr")
        assert code == 0
        seen.add(issued["serial"])
    assert seen == {"1", "2", "3"}


# ------------------------------------------------------------------ certificate and secrets


def test_a_certificate_without_critical_timestamping_eku_cannot_issue(run_cli, test_cert, tmp_path):
    pfx, _pem, env = test_cert
    code, payload = run_cli("tsa-issue", "--tsa-pfx", pfx, "--pass-env", env, "--policy-oid", POLICY, "--serial", 1, "--digest", _digest(b"q"), "--out", tmp_path / "q.tsr")
    assert code == 4 and payload["error"]["code"] == "invalid_tsa_certificate"


def test_passphrase_never_reaches_output_and_missing_env_is_a_usage_error(tsa, tmp_path):
    proc = run_subprocess(
        "tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", PASS_ENV, "--policy-oid", POLICY, "--serial", 5,
        "--digest", _digest(b"p"), "--out", tmp_path / "p.tsr", env={PASS_ENV: PASSPHRASE},
    )
    assert proc.returncode == 0
    assert PASSPHRASE.encode() not in proc.stdout + proc.stderr
    wrong = run_subprocess(
        "tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", PASS_ENV, "--policy-oid", POLICY, "--serial", 5,
        "--digest", _digest(b"p"), "--out", tmp_path / "w.tsr", env={PASS_ENV: "senha-errada-XYZ"},
    )
    assert wrong.returncode == 4
    assert b"senha-errada-XYZ" not in wrong.stdout + wrong.stderr
    missing = run_subprocess(
        "tsa-issue", "--tsa-pfx", tsa["pfx"], "--pass-env", "PDFTOOL_TSA_UNSET_VAR", "--policy-oid", POLICY, "--serial", 5,
        "--digest", _digest(b"p"), "--out", tmp_path / "m.tsr",
    )
    assert missing.returncode == 2 and b"missing_passphrase" in missing.stdout


def test_rsa_test_tsa_also_issues_valid_tokens(run_cli, tmp_path):
    from pdftool.tsa import generate_test_tsa

    generate_test_tsa(tmp_path / "rsa.pfx", PASS_ENV, tmp_path / "rsa-root.pem", days=5, key_type="rsa-3072")
    out = tmp_path / "rsa.tsr"
    code, issued = run_cli("tsa-issue", "--tsa-pfx", tmp_path / "rsa.pfx", "--pass-env", PASS_ENV, "--policy-oid", POLICY, "--serial", 3, "--digest", _digest(b"r"), "--out", out)
    assert code == 0 and issued["status"] == "granted"
    code, verified = run_cli("tsa-verify", "--token", out, "--digest", _digest(b"r"), "--trust", tmp_path / "rsa-root.pem")
    assert verified["valid"] is True and verified["trusted"] is True
