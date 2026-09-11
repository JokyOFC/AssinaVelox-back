"""``sign --tsa-*``: signature time stamp from the operator TSA. Declared profile stays B-B."""

from __future__ import annotations

import pytest
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import validate_pdf_signature
from pyhanko_certvalidator import ValidationContext

from conftest import make_pdf
from pdftool.validate import load_trust_roots

POLICY = "2.25.1"
TSA_ENV = "PDFTOOL_TSA_SIGN_PASS"


@pytest.fixture
def tsa(tmp_path, monkeypatch):
    from pdftool.tsa import generate_test_tsa

    monkeypatch.setenv(TSA_ENV, "tsa-pass-sign")
    generate_test_tsa(tmp_path / "tsa.pfx", TSA_ENV, tmp_path / "tsa-root.pem", days=10, key_type="ec-p256")
    return tmp_path / "tsa.pfx", tmp_path / "tsa-root.pem"


def _sign(run_cli, test_cert, tsa, src, out, serial=11, *extra):
    pfx, _pem, env = test_cert
    tsa_pfx, _root = tsa
    return run_cli(
        "sign", "--in", src, "--out", out, "--pfx", pfx, "--pass-env", env,
        "--tsa-pfx", tsa_pfx, "--tsa-pass-env", TSA_ENV, "--tsa-serial", serial, "--tsa-policy-oid", POLICY, *extra,
    )


def test_signature_timestamp_is_embedded_but_the_declared_profile_stays_b_b(run_cli, test_cert, tsa, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    code, signed = _sign(run_cli, test_cert, tsa, src, tmp_path / "out.pdf", 11)
    assert code == 0
    assert signed["profile"] == "PAdES-B-B"
    assert signed["timestamp"] is None  # PHP contract of the declared B-B profile
    stamp = signed["signature_timestamp"]
    assert stamp["serial"] == "11"
    assert stamp["tsa_kind"] == "operator" and stamp["announced"] is False
    assert stamp["unannounced_profile"] == "PAdES-B-T"
    assert "não é carimbo ICP-Brasil" in stamp["label"]

    code, validated = run_cli("validate", "--in", tmp_path / "out.pdf", "--trust", test_cert[1])
    assert validated["all_valid"] is True and validated["all_covering"] is True

    with (tmp_path / "out.pdf").open("rb") as handle:
        sig = PdfFileReader(handle).embedded_signatures[0]
        signer_vc = ValidationContext(trust_roots=load_trust_roots([test_cert[1]]), allow_fetching=False)
        ts_vc = ValidationContext(trust_roots=load_trust_roots([tsa[1]]), allow_fetching=False)
        status = validate_pdf_signature(sig, signer_validation_context=signer_vc, ts_validation_context=ts_vc)
        assert status.timestamp_validity is not None
        assert status.timestamp_validity.intact and status.timestamp_validity.valid
        assert status.timestamp_validity.trusted is True


def test_a_later_signature_preserves_the_timestamped_one(run_cli, test_cert, tsa, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "Contrato"}])
    _sign(run_cli, test_cert, tsa, src, tmp_path / "r1.pdf", 12)
    pfx, pem, env = test_cert
    code, _ = run_cli("sign", "--in", tmp_path / "r1.pdf", "--out", tmp_path / "r2.pdf", "--pfx", pfx, "--pass-env", env)
    assert code == 0
    code, validated = run_cli("validate", "--in", tmp_path / "r2.pdf", "--trust", pem)
    assert validated["signature_count"] == 2
    assert validated["all_valid"] is True
    assert validated["signatures"][0]["intact"] and validated["signatures"][1]["coverage"] == "ENTIRE_FILE"


def test_incomplete_tsa_options_are_a_usage_error(run_cli, test_cert, tsa, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "x"}])
    pfx, _pem, env = test_cert
    code, payload = run_cli("sign", "--in", src, "--out", tmp_path / "o.pdf", "--pfx", pfx, "--pass-env", env, "--tsa-pfx", tsa[0])
    assert code == 2 and payload["error"]["code"] == "tsa_options_incomplete"
    assert not (tmp_path / "o.pdf").exists()


def test_without_tsa_options_sign_is_unchanged(run_cli, test_cert, tmp_path):
    src = make_pdf(tmp_path / "in.pdf", [{"text": "x"}])
    pfx, _pem, env = test_cert
    code, signed = run_cli("sign", "--in", src, "--out", tmp_path / "o.pdf", "--pfx", pfx, "--pass-env", env)
    assert code == 0 and signed["profile"] == "PAdES-B-B"
    assert signed["timestamp"] is None and signed["signature_timestamp"] is None


def test_one_allocated_serial_yields_exactly_one_real_token(test_cert, tsa, tmp_path):
    import asyncio

    from pdftool.tsa import build_request, load_tsa_credentials
    from pdftool.timestamp import OperatorLocalTimeStamper

    stamper = OperatorLocalTimeStamper(load_tsa_credentials(tsa[0], TSA_ENV), 99, POLICY)
    asyncio.run(stamper.async_dummy_response("sha256"))
    assert stamper.issued is None  # the size-estimation dummy is not an issuance
    asyncio.run(stamper.async_request_tsa_response(build_request(b"\x01" * 32, "sha256")))
    assert stamper.issued["serial"] == "99"
    from pdftool.errors import ProcessingError

    with pytest.raises(ProcessingError):
        asyncio.run(stamper.async_request_tsa_response(build_request(b"\x02" * 32, "sha256")))
