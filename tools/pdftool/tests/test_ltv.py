"""P3-LTV: PAdES B-T / B-LT / B-LTA, archive re-stamping and long-term validation (roadmap 3.6).

Everything runs offline: a TEST PKI (``pdftool.ltv.TestPki``) whose certificates declare a CRL
distribution point and an OCSP URL under ``.invalid``; the CRL/OCSP material is handed over as
files. The "TSA that does not answer" is a closed port on 127.0.0.1 (no external network).
The announced profile is always PAdES-B-B (roadmap T2) and nothing is ICP-Brasil (T3).
"""

from __future__ import annotations

import datetime as dt
import socket
from pathlib import Path
from types import SimpleNamespace

import pytest

from conftest import make_pdf, run_subprocess
from pdftool.errors import ProcessingError
from pdftool.ltv import TestPki, TsaSpec, analyse, ltv_refresh, ltv_sign
from pdftool.validate import load_trust_roots

PASS_ENV = "PDFTOOL_LTV_TEST_PASS"
PASSWORD = "senha-ltv-teste-Z8!"
POLICY = "2.25.1"


def _closed_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


@pytest.fixture
def pki(tmp_path, monkeypatch):
    monkeypatch.setenv(PASS_ENV, PASSWORD)
    t0 = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
    authority = TestPki(now=t0, days=30)
    signer_key, signer_cert = authority.signer(days=30)
    tsa_key, tsa_cert = authority.tsa(days=10)
    d = tmp_path / "pki"
    authority.write_pfx(d / "signer.pfx", signer_key, signer_cert, PASS_ENV, b"signer")
    authority.write_pfx(d / "tsa.pfx", tsa_key, tsa_cert, PASS_ENV, b"tsa")
    (d / "root.pem").write_bytes(authority.pem(authority.root))
    (d / "root.crl").write_bytes(authority.crl())
    (d / "signer.ocsp").write_bytes(authority.ocsp(signer_cert))
    (d / "tsa.ocsp").write_bytes(authority.ocsp(tsa_cert))
    return SimpleNamespace(
        t0=t0,
        authority=authority,
        signer_cert=signer_cert,
        tsa_cert=tsa_cert,
        signer_pfx=d / "signer.pfx",
        tsa_pfx=d / "tsa.pfx",
        root=d / "root.pem",
        crl=d / "root.crl",
        signer_ocsp=d / "signer.ocsp",
        tsa_ocsp=d / "tsa.ocsp",
        dir=d,
    )


def _cli_sign(run_cli, pki, src, out, level, serials=(11, 12), revinfo=("crl",), extra=()):
    args = [
        "ltv-sign", "--in", src, "--out", out, "--pfx", pki.signer_pfx, "--pass-env", PASS_ENV, "--level", level,
        "--tsa-pfx", pki.tsa_pfx, "--tsa-pass-env", PASS_ENV, "--tsa-policy-oid", POLICY, "--trust", pki.root,
    ]
    for serial in serials:
        args += ["--tsa-serial", serial]
    if "crl" in revinfo:
        args += ["--crl", pki.crl]
    if "ocsp" in revinfo:
        args += ["--ocsp", pki.signer_ocsp, "--ocsp", pki.tsa_ocsp]
    return run_cli(*args, *extra)


def _validate(run_cli, pki, path, *extra):
    return run_cli("ltv-validate", "--in", path, "--trust", pki.root, *extra)


def _spec(pki, serials, pfx=None):
    return TsaSpec(kind="operator", pfx=pfx or pki.tsa_pfx, pass_env=PASS_ENV, serials=list(serials), policy_oid=POLICY)


# --------------------------------------------------------------------------- B-T


def test_b_t_embeds_the_signature_time_stamp_and_still_announces_b_b(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    code, signed = _cli_sign(run_cli, pki, src, tmp_path / "bt.pdf", "B-T", serials=(21,), revinfo=())
    assert code == 0, signed
    assert signed["announced_profile"] == "PAdES-B-B" and signed["profile"] == "PAdES-B-B" and signed["announced"] is False
    assert signed["effective_level"] == "B-T" and signed["degraded"] is False
    assert signed["icp_brasil"] is False and signed["tsa_kind"] == "operator"
    assert signed["serials_used"] == ["21"] and signed["serials_unused"] == []
    stamp = signed["timestamps"][0]
    assert stamp["purpose"] == "signature" and "não é carimbo ICP-Brasil" in stamp["label"]

    code, report = _validate(run_cli, pki, tmp_path / "bt.pdf")
    assert code == 0
    assert report["effective_level"] == "B-T"
    assert report["dss"]["present"] is False and report["document_timestamp_count"] == 0
    assert report["signatures"][0]["signature_timestamp"]["present"] is True
    # B-T carries no revocation material and the certificates declare a CRL: trust is NOT
    # claimed until the caller hands the CRL over (hard-fail, never soft-fail — R5).
    assert report["signatures"][0]["trusted"] is False
    code, report = _validate(run_cli, pki, tmp_path / "bt.pdf", "--crl", pki.crl)
    assert report["signatures"][0]["trusted"] is True
    assert report["signatures"][0]["signature_timestamp"]["trusted"] is True
    assert report["last_timestamp_at"] == report["signatures"][0]["signature_timestamp"]["gen_time"]

    # The B-B contract of `validate` is unchanged for this file.
    code, plain = run_cli("validate", "--in", tmp_path / "bt.pdf", "--trust", pki.root)
    assert plain["all_valid"] is True and plain["all_covering"] is True


# --------------------------------------------------------------------------- B-LT


def test_b_lt_embeds_dss_with_the_crl_and_a_vri_entry(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    code, signed = _cli_sign(run_cli, pki, src, tmp_path / "blt.pdf", "B-LT", serials=(31, 32))
    assert code == 0, signed
    assert signed["effective_level"] == "B-LT" and signed["validated_level"] == "B-LT"
    # B-LT needs one token only: the second allocated serial is reported as unused.
    assert signed["serials_used"] == ["31"] and signed["serials_unused"] == ["32"]
    assert signed["revocation_embedded"] is True

    code, report = _validate(run_cli, pki, tmp_path / "blt.pdf")
    assert report["effective_level"] == "B-LT"
    assert report["dss"]["present"] is True and report["dss"]["crls"] >= 1 and report["dss"]["vri_entries"] >= 1
    sig = report["signatures"][0]
    assert sig["vri_present"] is True
    assert sig["revocation"]["signer"]["covered"] is True
    assert sig["revocation"]["signature_timestamp"]["covered"] is True
    assert sig["modification_level"] == "LTA_UPDATES" and sig["later_changes_lta_only"] is True


def test_b_lt_also_works_with_offline_ocsp_responses(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    code, signed = _cli_sign(run_cli, pki, src, tmp_path / "ocsp.pdf", "B-LT", serials=(41,), revinfo=("ocsp",))
    assert code == 0, signed
    assert signed["effective_level"] == "B-LT", signed["degradations"]
    code, report = _validate(run_cli, pki, tmp_path / "ocsp.pdf")
    assert report["dss"]["ocsps"] >= 1 and report["effective_level"] == "B-LT"


# --------------------------------------------------------------------------- B-LTA


def test_b_lta_adds_a_document_time_stamp_and_reports_the_archive_stamp(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    code, signed = _cli_sign(run_cli, pki, src, tmp_path / "blta.pdf", "B-LTA", serials=(51, 52))
    assert code == 0, signed
    assert signed["effective_level"] == "B-LTA" and signed["validated_level"] == "B-LTA"
    assert [t["purpose"] for t in signed["timestamps"]] == ["signature", "document"]
    assert signed["serials_used"] == ["51", "52"]
    assert signed["announced_profile"] == "PAdES-B-B"
    assert signed["archive_timestamp"]["tsa_cert_not_after"] is not None

    code, report = _validate(run_cli, pki, tmp_path / "blta.pdf")
    assert report["effective_level"] == "B-LTA"
    assert report["document_timestamp_count"] == 1 and report["timestamp_chain_valid"] is True
    assert report["all_later_changes_lta_only"] is True
    assert report["last_timestamp_at"] == report["document_timestamps"][0]["gen_time"]
    assert report["signatures"][0]["signature_policy_identifier_present"] is False
    assert report["signatures"][0]["policy_conformance"] == "not_checked"

    code, plain = run_cli("validate", "--in", tmp_path / "blta.pdf", "--trust", pki.root)
    assert plain["all_valid"] is True
    # Honest: later LTA revisions exist, so the signature no longer covers the entire file.
    assert plain["signatures"][0]["coverage"] == "ENTIRE_REVISION"
    assert plain["signatures"][0]["modification_level"] == "LTA_UPDATES"


# --------------------------------------------------------------------------- explicit degradation


def test_missing_revocation_material_degrades_explicitly_to_b_t(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    code, signed = _cli_sign(run_cli, pki, src, tmp_path / "deg.pdf", "B-LTA", serials=(61, 62), revinfo=())
    assert code == 0, signed
    assert signed["effective_level"] == "B-T" and signed["degraded"] is True
    assert signed["degradations"][0]["step"] == "validation_info"
    assert signed["serials_used"] == ["61"] and signed["serials_unused"] == ["62"]
    assert signed["revocation_embedded"] is False
    code, report = _validate(run_cli, pki, tmp_path / "deg.pdf")
    assert report["effective_level"] == "B-T" and report["dss"]["present"] is False


def test_revoked_signer_never_reaches_b_lt(run_cli, pki, tmp_path):
    (pki.dir / "revoked.crl").write_bytes(pki.authority.crl(revoked=[pki.signer_cert]))
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    code, signed = run_cli(
        "ltv-sign", "--in", src, "--out", tmp_path / "rev.pdf", "--pfx", pki.signer_pfx, "--pass-env", PASS_ENV, "--level", "B-LT",
        "--tsa-pfx", pki.tsa_pfx, "--tsa-pass-env", PASS_ENV, "--tsa-serial", 71, "--tsa-policy-oid", POLICY,
        "--trust", pki.root, "--crl", pki.dir / "revoked.crl",
    )
    assert code == 0, signed
    assert signed["effective_level"] == "B-T" and signed["degraded"] is True


def test_a_tsa_that_does_not_answer_leaves_b_b_and_records_it(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    code, signed = run_cli(
        "ltv-sign", "--in", src, "--out", tmp_path / "bb.pdf", "--pfx", pki.signer_pfx, "--pass-env", PASS_ENV, "--level", "B-LTA",
        "--tsa-url", f"http://127.0.0.1:{_closed_port()}/tsa", "--tsa-timeout", 2, "--trust", pki.root, "--crl", pki.crl,
    )
    assert code == 0, signed
    assert signed["effective_level"] == "B-B" and signed["degraded"] is True
    assert signed["degradations"][0]["step"] == "signature_timestamp"
    assert signed["degradations"][0]["code"] == "tsa_unavailable"
    assert signed["timestamps"] == [] and signed["announced_profile"] == "PAdES-B-B"
    code, report = _validate(run_cli, pki, tmp_path / "bb.pdf")
    assert report["effective_level"] == "B-B" and report["all_valid"] is True


# --------------------------------------------------------------------------- re-stamping


def test_restamp_with_an_advanced_clock_keeps_the_file_valid_and_adds_a_layer(pki, tmp_path):
    roots = load_trust_roots([pki.root])
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    first = tmp_path / "lta-1.pdf"
    signed = ltv_sign(src, first, pki.signer_pfx, PASS_ENV, "B-LTA", _spec(pki, [81, 82]), trust_paths=[pki.root], crl_paths=[pki.crl])
    assert signed["effective_level"] == "B-LTA"

    # Nine days later — before the first TSA certificate (10 days) expires — a new TSA
    # certificate and a fresh CRL are in place, and the archive stamp is renewed.
    t1 = pki.t0 + dt.timedelta(days=9)
    tsa2_key, tsa2_cert = pki.authority.tsa(days=400, cn="AssinaVelox TSA LTV 2 TESTE")
    pki.authority.write_pfx(pki.dir / "tsa2.pfx", tsa2_key, tsa2_cert, PASS_ENV, b"tsa2")
    (pki.dir / "crl-t1.crl").write_bytes(pki.authority.crl(this_update=t1))
    second = tmp_path / "lta-2.pdf"
    refreshed = ltv_refresh(
        first, second, _spec(pki, [83], pfx=pki.dir / "tsa2.pfx"), trust_paths=[pki.root], crl_paths=[pki.dir / "crl-t1.crl"], clock=lambda: t1
    )
    assert refreshed["document_timestamps_before"] == 1 and refreshed["document_timestamps_after"] == 2
    assert refreshed["effective_level"] == "B-LTA" and refreshed["timestamp_chain_valid"] is True
    assert refreshed["serials_used"] == ["83"] and refreshed["timestamps"][0]["purpose"] == "archive"
    assert refreshed["sha256"] != refreshed["sha256_before"]
    # Incremental: every previous byte is kept, the new layer is appended.
    assert second.read_bytes().startswith(first.read_bytes())
    assert refreshed["last_timestamp_at"].startswith(t1.date().isoformat())

    # Thirty days after signing the first TSA certificate has expired. The re-stamped file is
    # still B-LTA (the second stamp vouches for the first); the original is not.
    t2 = pki.t0 + dt.timedelta(days=30)
    report = analyse(second, roots, at=t2)
    assert report["effective_level"] == "B-LTA", report
    assert report["timestamp_chain_valid"] is True
    first_ts, last_ts = report["document_timestamps"]
    assert dt.datetime.fromisoformat(first_ts["tsa_cert_not_after"]) < t2
    assert first_ts["validated_at"].startswith(t1.date().isoformat())

    stale = analyse(first, roots, at=t2)
    assert stale["timestamp_chain_valid"] is False
    assert stale["effective_level"] == "B-LT"


def test_restamp_after_the_tsa_certificate_expired_is_refused(pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    first = tmp_path / "lta.pdf"
    ltv_sign(src, first, pki.signer_pfx, PASS_ENV, "B-LTA", _spec(pki, [91, 92]), trust_paths=[pki.root], crl_paths=[pki.crl])
    late = pki.t0 + dt.timedelta(days=11)
    with pytest.raises(ProcessingError) as caught:
        ltv_refresh(first, tmp_path / "late.pdf", _spec(pki, [93]), trust_paths=[pki.root], crl_paths=[pki.crl], clock=lambda: late)
    assert caught.value.code == "archive_timestamp_expired"
    assert not (tmp_path / "late.pdf").exists()


def test_refresh_with_an_unreachable_tsa_fails_and_writes_nothing(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    _cli_sign(run_cli, pki, src, tmp_path / "lta.pdf", "B-LTA", serials=(101, 102))
    code, payload = run_cli(
        "ltv-refresh", "--in", tmp_path / "lta.pdf", "--out", tmp_path / "r.pdf",
        "--tsa-url", f"http://127.0.0.1:{_closed_port()}/tsa", "--tsa-timeout", 2, "--trust", pki.root, "--crl", pki.crl,
    )
    assert code == 3 and payload["error"]["code"] == "tsa_unavailable"
    assert not (tmp_path / "r.pdf").exists()


def test_refresh_of_a_b_lt_file_turns_it_into_b_lta(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    _cli_sign(run_cli, pki, src, tmp_path / "blt.pdf", "B-LT", serials=(111,))
    code, refreshed = run_cli(
        "ltv-refresh", "--in", tmp_path / "blt.pdf", "--out", tmp_path / "r.pdf",
        "--tsa-pfx", pki.tsa_pfx, "--tsa-pass-env", PASS_ENV, "--tsa-serial", 112, "--tsa-policy-oid", POLICY,
        "--trust", pki.root, "--crl", pki.crl,
    )
    assert code == 0, refreshed
    assert refreshed["level_before"] == "B-LT" and refreshed["effective_level"] == "B-LTA"
    assert refreshed["announced_profile"] == "PAdES-B-B" and refreshed["icp_brasil"] is False


# --------------------------------------------------------------------------- T3 / usage / secrets


def test_icp_brasil_is_never_accepted_as_tsa_kind(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "x"}])
    code, payload = _cli_sign(run_cli, pki, src, tmp_path / "o.pdf", "B-T", serials=(1,), revinfo=(), extra=("--tsa-kind", "icp_brasil"))
    assert code == 2 and payload["error"]["code"] == "icp_brasil_not_available"
    assert not (tmp_path / "o.pdf").exists()


def test_the_in_process_tsa_is_always_the_operator_tsa(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "x"}])
    code, payload = _cli_sign(run_cli, pki, src, tmp_path / "o.pdf", "B-T", serials=(1,), revinfo=(), extra=("--tsa-kind", "commercial"))
    assert code == 2 and payload["error"]["code"] == "invalid_tsa_kind"


def test_b_lt_without_trust_roots_is_a_usage_error(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "x"}])
    code, payload = run_cli(
        "ltv-sign", "--in", src, "--out", tmp_path / "o.pdf", "--pfx", pki.signer_pfx, "--pass-env", PASS_ENV, "--level", "B-LT",
        "--tsa-pfx", pki.tsa_pfx, "--tsa-pass-env", PASS_ENV, "--tsa-serial", 1, "--tsa-policy-oid", POLICY,
    )
    assert code == 2 and payload["error"]["code"] == "trust_required"


def test_soft_fail_revocation_is_not_offered(run_cli, pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "x"}])
    code, payload = _cli_sign(run_cli, pki, src, tmp_path / "o.pdf", "B-LT", extra=("--revocation-mode", "soft-fail"))
    assert code == 2 and payload["error"]["code"] == "usage_error"


def test_the_passphrase_never_reaches_stdout_or_stderr(pki, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "x"}])
    proc = run_subprocess(
        "ltv-sign", "--in", src, "--out", tmp_path / "o.pdf", "--pfx", pki.signer_pfx, "--pass-env", PASS_ENV, "--level", "B-LTA",
        "--tsa-pfx", pki.tsa_pfx, "--tsa-pass-env", PASS_ENV, "--tsa-serial", 7, "--tsa-serial", 8, "--tsa-policy-oid", POLICY,
        "--trust", pki.root, "--crl", pki.crl,
        env={PASS_ENV: PASSWORD},
    )
    assert proc.returncode == 0, proc.stdout
    assert PASSWORD.encode() not in proc.stdout and PASSWORD.encode() not in proc.stderr
    wrong = run_subprocess(
        "ltv-sign", "--in", src, "--out", tmp_path / "o2.pdf", "--pfx", pki.signer_pfx, "--pass-env", PASS_ENV, "--level", "B-T",
        "--tsa-pfx", pki.tsa_pfx, "--tsa-pass-env", PASS_ENV, "--tsa-serial", 9, "--tsa-policy-oid", POLICY,
        env={PASS_ENV: "senha-errada"},
    )
    assert wrong.returncode == 4 and b"senha-errada" not in wrong.stdout + wrong.stderr


def test_gen_test_pki_writes_a_labelled_test_pki(run_cli, tmp_path, monkeypatch):
    monkeypatch.setenv(PASS_ENV, PASSWORD)
    code, out = run_cli("ltv-gen-test-pki", "--out-dir", tmp_path / "pki", "--pass-env", PASS_ENV, "--days", 5)
    assert code == 0 and out["test_only"] is True
    assert "TESTE" in out["signer_subject"] and "TESTE" in out["tsa_subject"] and "TESTE" in out["root_subject"]
    for path in out["files"].values():
        assert Path(path).is_file()
    assert PASSWORD not in str(out)
