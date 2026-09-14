"""argparse front-end. Exactly one JSON object on stdout; everything else on stderr."""

from __future__ import annotations

import argparse
import contextlib
import json
import logging
import sys
import traceback
from pathlib import Path
from typing import Optional, Sequence

from pdftool import __version__
from pdftool.errors import EXIT_OK, EXIT_PROCESSING, PdfToolError, UsageError


class _Parser(argparse.ArgumentParser):
    """ArgumentParser that reports usage problems as JSON (exit 2) and keeps stdout clean."""

    def error(self, message):  # noqa: D401 - argparse hook
        raise UsageError("usage_error", message)

    def print_help(self, file=None):
        super().print_help(file or sys.stderr)

    def print_usage(self, file=None):
        super().print_usage(file or sys.stderr)


def _path(value: str) -> Path:
    return Path(value).expanduser()


def _cmd_inspect(args):
    from pdftool.inspect_cmd import inspect_pdf

    return inspect_pdf(args.input)


def _cmd_image2pdf(args):
    from pdftool.images import image_to_pdf

    return image_to_pdf(args.input, args.output, page=args.page, margin_pt=args.margin_pt)


def _cmd_compose(args):
    from pdftool.compose import compose

    return compose(args.plan, args.output)


def _cmd_append(args):
    from pdftool.compose import append_pdfs

    return append_pdfs(args.base, args.extra, args.output)


def _cmd_sign(args):
    from pdftool.sign import sign_pdf

    return sign_pdf(
        args.input,
        args.output,
        args.pfx,
        args.pass_env,
        field_name=args.field_name,
        reason=args.reason,
        location=args.location,
        contact=args.contact,
        visible=args.visible,
        # K-TSA (optional signature time stamp from the operator TSA).
        tsa_pfx=getattr(args, "tsa_pfx", None),
        tsa_pass_env=getattr(args, "tsa_pass_env", None),
        tsa_serial=getattr(args, "tsa_serial", None),
        tsa_policy_oid=getattr(args, "tsa_policy_oid", None),
        tsa_accuracy_ms=getattr(args, "tsa_accuracy_ms", 1000),
    )


def _cmd_tsa_issue(args):
    from pdftool.tsa import issue

    return issue(
        args.output,
        args.tsa_pfx,
        args.pass_env,
        args.policy_oid,
        serial=args.serial,
        serial_file=args.serial_file,
        request_path=args.request,
        digest_hex=args.digest,
        hash_algorithm=args.hash_alg,
        nonce=args.nonce,
        cert_req=not args.no_cert_req,
        accuracy_ms=args.accuracy_ms,
        token_out=args.token_out,
    )


def _cmd_tsa_verify(args):
    from pdftool.tsa import verify

    return verify(
        args.token,
        digest_hex=args.digest,
        data_path=args.data,
        hash_algorithm=args.hash_alg,
        trust_paths=args.trust,
        tsa_cert_paths=args.tsa_cert,
        expected_nonce=args.nonce,
        expected_policy=args.policy_oid,
    )


def _cmd_tsa_gen_test(args):
    from pdftool.tsa import generate_test_tsa

    return generate_test_tsa(
        args.out_pfx,
        args.pass_env,
        args.out_root_pem,
        out_chain_pem=args.out_chain_pem,
        subject=args.subject,
        days=args.days,
        key_type=args.key,
    )


def _add_tsa_commands(sub) -> None:
    """K-TSA: RFC 3161 operator TSA (not ICP-Brasil). Registered additively."""
    p = sub.add_parser("tsa-issue", help="issue an RFC 3161 TimeStampResp with the OPERATOR TSA key (not ICP-Brasil)")
    p.add_argument("--tsa-pfx", dest="tsa_pfx", type=_path, required=True, help="PKCS#12 with the TSA key + certificate (+ chain)")
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the environment variable holding the PKCS#12 passphrase")
    p.add_argument("--policy-oid", dest="policy_oid", required=True, help="TSA policy OID written in TSTInfo.policy")
    p.add_argument("--serial", type=int, default=None, help="serial allocated by the caller (database sequence)")
    p.add_argument("--serial-file", dest="serial_file", type=_path, default=None, help="standalone mode: locked counter file")
    p.add_argument("--request", type=_path, default=None, help="DER TimeStampReq (application/timestamp-query body)")
    p.add_argument("--digest", default=None, help="hex digest to stamp (instead of --request)")
    p.add_argument("--hash-alg", dest="hash_alg", type=str.lower, default="sha256", choices=["sha256", "sha384", "sha512"])
    p.add_argument("--nonce", type=int, default=None, help="nonce for --digest mode")
    p.add_argument("--no-cert-req", dest="no_cert_req", action="store_true", help="--digest mode: do not embed the TSA certificate")
    p.add_argument("--accuracy-ms", dest="accuracy_ms", type=int, default=1000)
    p.add_argument("--out", dest="output", type=_path, required=True, help="output TimeStampResp (DER)")
    p.add_argument("--token-out", dest="token_out", type=_path, default=None, help="also write the bare TimeStampToken (DER)")
    p.set_defaults(func=_cmd_tsa_issue)

    p = sub.add_parser("tsa-verify", help="verify an RFC 3161 token against a digest and a TSA trust anchor")
    p.add_argument("--token", type=_path, required=True, help="TimeStampResp (.tsr) or TimeStampToken (DER)")
    p.add_argument("--digest", default=None, help="expected hex digest")
    p.add_argument("--data", type=_path, default=None, help="file whose digest is expected (instead of --digest)")
    p.add_argument("--hash-alg", dest="hash_alg", type=str.lower, default=None, choices=["sha256", "sha384", "sha512"])
    p.add_argument("--trust", action="append", type=_path, default=[], help="trusted TSA root (PEM/DER); repeatable")
    p.add_argument("--tsa-cert", dest="tsa_cert", action="append", type=_path, default=[], help="TSA/intermediate certificate when not embedded")
    p.add_argument("--nonce", type=int, default=None, help="expected nonce")
    p.add_argument("--policy-oid", dest="policy_oid", default=None, help="expected policy OID")
    p.set_defaults(func=_cmd_tsa_verify)

    p = sub.add_parser("tsa-gen-test", help="generate a TEST operator TSA (internal root + timeStamping cert) - never for production")
    p.add_argument("--out-pfx", dest="out_pfx", type=_path, required=True)
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the environment variable holding the passphrase")
    p.add_argument("--out-root-pem", dest="out_root_pem", type=_path, required=True, help="internal TEST root (trust anchor for tsa-verify)")
    p.add_argument("--out-chain-pem", dest="out_chain_pem", type=_path, default=None, help="TSA certificate + root (PEM)")
    p.add_argument("--subject", default="CN=AssinaVelox TSA TESTE,O=AssinaVelox,C=BR")
    p.add_argument("--days", type=int, default=365)
    p.add_argument("--key", default="rsa-3072", choices=["rsa-3072", "ec-p256"])
    p.set_defaults(func=_cmd_tsa_gen_test)


def _cmd_inspect_cert(args):
    from pdftool.inspect_cert import inspect_certificate

    return inspect_certificate(args.pfx, args.pass_env)


def _cmd_participant_sign(args):
    from pdftool.participant_sign import participant_sign

    return participant_sign(
        args.input,
        args.output,
        args.pfx,
        args.pass_env,
        args.field_name,
        reason=args.reason,
        location=args.location,
        trust_paths=args.trust,
        expect_fingerprint=args.expect_fingerprint,
    )


def _cmd_gen_test_participant_cert(args):
    from pdftool.inspect_cert import generate_participant_test_cert

    return generate_participant_test_cert(
        args.out_pfx,
        args.pass_env,
        common_name=args.name,
        cpf=args.cpf,
        days=args.days,
        valid_from_days=args.valid_from_days,
        key_usage=args.key_usage,
        no_key=args.no_key,
        self_signed=args.self_signed,
        out_ca_pem=args.out_ca_pem,
    )


def _add_participant_a1_commands(sub) -> None:
    """K-A1: the participant's own A1 certificate (roadmap 2.12). Registered additively."""
    p = sub.add_parser("inspect-cert", help="check a participant PKCS#12 and print public facts only (no key material)")
    p.add_argument("--pfx", type=_path, required=True, help="PKCS#12 file with key + certificate")
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the environment variable holding the PKCS#12 passphrase")
    p.set_defaults(func=_cmd_inspect_cert)

    p = sub.add_parser("participant-sign", help="incremental PAdES B-B signature with the participant's own A1, then validate ALL signatures")
    p.add_argument("--in", dest="input", type=_path, required=True)
    p.add_argument("--out", dest="output", type=_path, required=True)
    p.add_argument("--pfx", type=_path, required=True, help="participant PKCS#12 file")
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the environment variable holding the PKCS#12 passphrase")
    p.add_argument("--field-name", dest="field_name", required=True, help="unique signature field name for this participant")
    p.add_argument("--reason", default=None)
    p.add_argument("--location", default=None)
    p.add_argument("--trust", action="append", type=_path, default=[], help="trusted root certificate (PEM or DER); repeatable")
    p.add_argument("--expect-fingerprint", dest="expect_fingerprint", default=None, help="SHA-256 fingerprint the certificate must have")
    p.set_defaults(func=_cmd_participant_sign)

    p = sub.add_parser("gen-test-participant-cert", help="TEST participant certificate issued by a throw-away TEST CA - not ICP-Brasil")
    p.add_argument("--out-pfx", dest="out_pfx", type=_path, required=True)
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the environment variable holding the passphrase")
    p.add_argument("--name", default="Participante", help="holder name (CN; 'TESTE' is always added)")
    p.add_argument("--cpf", default=None, help="11-digit CPF written as ICP-Brasil otherName 2.16.76.1.3.1 and in the CN suffix")
    p.add_argument("--days", type=int, default=30)
    p.add_argument("--valid-from-days", dest="valid_from_days", type=int, default=0, help="shift notBefore by N days (negative = past)")
    p.add_argument("--key-usage", dest="key_usage", default="signing", choices=["signing", "encipherment"])
    p.add_argument("--no-key", dest="no_key", action="store_true", help="write the certificate WITHOUT its private key")
    p.add_argument("--self-signed", dest="self_signed", action="store_true")
    p.add_argument("--out-ca-pem", dest="out_ca_pem", type=_path, default=None, help="write the TEST CA (or the self-signed cert) as PEM")
    p.set_defaults(func=_cmd_gen_test_participant_cert)


def _cmd_verify_incremental(args):
    from pdftool.incremental import verify_incremental

    return verify_incremental(
        args.base,
        args.input,
        trust_paths=args.trust,
        permitted_levels=args.permitted_level or None,
        expect_cpf_env=args.expect_cpf_env,
    )


def _add_govbr_commands(sub) -> None:
    """P3-GOV: PDF signed outside the platform and returned (roadmap 3.5). Registered additively."""
    p = sub.add_parser("verify-incremental", help="does the returned PDF extend the expected revision with exactly one new sound signature?")
    p.add_argument("--base", type=_path, required=True, help="expected revision (the exact bytes handed to the participant)")
    p.add_argument("--in", dest="input", type=_path, required=True, help="returned PDF")
    p.add_argument("--trust", action="append", type=_path, default=[], help="pinned trust anchor (PEM or DER); repeatable")
    p.add_argument("--permitted-level", dest="permitted_level", action="append", type=str.upper, default=[],
                   choices=["NONE", "FORM_FILLING", "ANNOTATIONS"], help="modification level allowed since the base; repeatable")
    p.add_argument("--expect-cpf-env", dest="expect_cpf_env", default=None, help="NAME of the env var holding the participant CPF (never argv)")
    p.set_defaults(func=_cmd_verify_incremental)


def _cmd_prepare_external(args):
    from pdftool.external import prepare_external

    return prepare_external(
        args.input,
        args.output,
        args.state_out,
        args.cert,
        chain_paths=args.chain,
        field_name=args.field_name,
        reason=args.reason,
        location=args.location,
        trust_paths=args.trust,
        bytes_reserved=args.bytes_reserved,
        prefer_pss=args.rsa_pss,
        expect_fingerprint=args.expect_fingerprint,
    )


def _cmd_embed_external(args):
    from pdftool.external import embed_external

    return embed_external(
        args.pending,
        args.state,
        args.output,
        signature_path=args.signature,
        cert_path=args.cert,
        chain_paths=args.chain,
        cms_path=args.cms,
        trust_paths=args.trust,
        expect_fingerprint=args.expect_fingerprint,
    )


def _add_external_commands(sub) -> None:
    """P3-EXT: signature made outside this process (A3 local component, roadmap 3.4). Registered additively."""
    p = sub.add_parser("prepare-external", help="write the pending revision with a signature placeholder and return the digest to sign (no key involved)")
    p.add_argument("--in", dest="input", type=_path, required=True, help="current revision (base + previous signatures)")
    p.add_argument("--out", dest="output", type=_path, required=True, help="pending revision with the empty /Contents placeholder")
    p.add_argument("--state-out", dest="state_out", type=_path, required=True, help="minimal state file (no secret) needed by embed-external")
    p.add_argument("--cert", type=_path, required=True, help="signer certificate announced by the component (PEM or DER)")
    p.add_argument("--chain", action="append", type=_path, default=[], help="chain certificate (PEM or DER); repeatable")
    p.add_argument("--field-name", dest="field_name", required=True, help="unique signature field name for this participant")
    p.add_argument("--reason", default=None)
    p.add_argument("--location", default=None)
    p.add_argument("--trust", action="append", type=_path, default=[], help="pinned trust anchor used to evaluate the chain (offline); repeatable")
    p.add_argument("--bytes-reserved", dest="bytes_reserved", type=int, default=16384, help="bytes reserved for the CMS (default 16384)")
    p.add_argument("--rsa-pss", dest="rsa_pss", action="store_true", help="RSA keys: announce RSASSA-PSS instead of PKCS#1 v1.5")
    p.add_argument("--expect-fingerprint", dest="expect_fingerprint", default=None, help="SHA-256 fingerprint the signer certificate must have")
    p.set_defaults(func=_cmd_prepare_external)

    p = sub.add_parser("embed-external", help="embed a raw signature (+ certificate) or a ready CMS into the pending revision and validate ALL signatures")
    p.add_argument("--pending", type=_path, required=True, help="pending revision written by prepare-external")
    p.add_argument("--state", type=_path, required=True, help="state file written by prepare-external")
    p.add_argument("--out", dest="output", type=_path, required=True, help="signed revision")
    p.add_argument("--signature", type=_path, default=None, help="raw mode: signature value (binary or Base64) over the prepared digest")
    p.add_argument("--cert", type=_path, default=None, help="raw mode: signer certificate (must be the announced one)")
    p.add_argument("--chain", action="append", type=_path, default=[], help="raw mode: chain certificate; repeatable")
    p.add_argument("--cms", type=_path, default=None, help="cms mode: detached CMS/PKCS#7 SignedData (DER, PEM or Base64)")
    p.add_argument("--trust", action="append", type=_path, default=[], help="pinned trust anchor (PEM or DER); repeatable")
    p.add_argument("--expect-fingerprint", dest="expect_fingerprint", default=None, help="SHA-256 fingerprint announced at prepare time")
    p.set_defaults(func=_cmd_embed_external)


def _cmd_validate(args):
    from pdftool.validate import validate_pdf

    return validate_pdf(args.input, trust_paths=args.trust, check_revocation=not args.no_revocation)


def _cmd_gen_test_cert(args):
    from pdftool.certs import generate_test_cert

    return generate_test_cert(args.out_pfx, args.pass_env, subject=args.subject, days=args.days, out_pem=args.out_pem)


def _cmd_cert_info(args):
    from pdftool.certs import describe_pkcs12

    return describe_pkcs12(args.pfx, args.pass_env)


def _cmd_selftest(args):
    from pdftool.selftest import run_selftest

    return run_selftest(keep=args.keep)


def build_parser() -> argparse.ArgumentParser:
    parser = _Parser(
        prog="pdftool",
        description="AssinaVelox PDF tool: inspect, compose, sign (PAdES B-B) and validate PDFs. Output: one JSON object on stdout.",
    )
    parser.add_argument("--version", action="version", version=f"pdftool {__version__}")
    sub = parser.add_subparsers(dest="command", metavar="<command>")
    sub.required = True

    p = sub.add_parser("inspect", help="describe a PDF (pages, boxes, rotation, signatures, encryption)")
    p.add_argument("--in", dest="input", type=_path, required=True, help="input PDF")
    p.set_defaults(func=_cmd_inspect)

    p = sub.add_parser("image2pdf", help="normalise a PNG/JPEG/WEBP image and wrap it in a one-page PDF")
    p.add_argument("--in", dest="input", type=_path, required=True, help="input image")
    p.add_argument("--out", dest="output", type=_path, required=True, help="output PDF")
    p.add_argument("--page", type=str.lower, choices=["a4", "letter", "fit"], default="a4", help="page size (default A4)")
    p.add_argument("--margin-pt", dest="margin_pt", type=float, default=36.0, help="margin in points for A4/letter (default 36)")
    p.set_defaults(func=_cmd_image2pdf)

    p = sub.add_parser("compose", help="flatten plan fields (text, images, checkboxes) onto a PDF")
    p.add_argument("--plan", type=_path, required=True, help="plan JSON file")
    p.add_argument("--out", dest="output", type=_path, required=True, help="output PDF")
    p.set_defaults(func=_cmd_compose)

    p = sub.add_parser("append", help="concatenate two PDFs (base pages followed by extra pages)")
    p.add_argument("--base", type=_path, required=True)
    p.add_argument("--extra", type=_path, required=True)
    p.add_argument("--out", dest="output", type=_path, required=True)
    p.set_defaults(func=_cmd_append)

    p = sub.add_parser("sign", help="apply one PAdES B-B signature (incremental update)")
    p.add_argument("--in", dest="input", type=_path, required=True)
    p.add_argument("--out", dest="output", type=_path, required=True)
    p.add_argument("--pfx", type=_path, required=True, help="PKCS#12 file with key + certificate")
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the environment variable holding the PKCS#12 passphrase")
    p.add_argument("--field-name", dest="field_name", default=None, help="signature field name (default AssinaVelox; suffixed if already signed)")
    p.add_argument("--reason", default=None)
    p.add_argument("--location", default=None)
    p.add_argument("--contact", default=None)
    p.add_argument("--visible", default=None, help='visible stamp: "<page>,<x>,<y>,<w>,<h>" (normalized, top-left origin)')
    # K-TSA: optional signature time stamp from the operator TSA (declared profile stays B-B).
    p.add_argument("--tsa-pfx", dest="tsa_pfx", type=_path, default=None, help="operator TSA PKCS#12 (adds a signature time stamp)")
    p.add_argument("--tsa-pass-env", dest="tsa_pass_env", default=None, help="NAME of the env var with the TSA PKCS#12 passphrase")
    p.add_argument("--tsa-serial", dest="tsa_serial", type=int, default=None, help="TSA serial allocated by the caller")
    p.add_argument("--tsa-policy-oid", dest="tsa_policy_oid", default=None)
    p.add_argument("--tsa-accuracy-ms", dest="tsa_accuracy_ms", type=int, default=1000)
    p.set_defaults(func=_cmd_sign)

    p = sub.add_parser("validate", help="validate every signature in a PDF")
    p.add_argument("--in", dest="input", type=_path, required=True)
    p.add_argument("--trust", action="append", type=_path, default=[], help="trusted root certificate (PEM or DER); repeatable")
    p.add_argument("--no-revocation", dest="no_revocation", action="store_true", help="accepted for compatibility; revocation is never checked")
    p.set_defaults(func=_cmd_validate)

    p = sub.add_parser("gen-test-cert", help="generate a self-signed TEST certificate (PKCS#12) - not ICP-Brasil")
    p.add_argument("--out-pfx", dest="out_pfx", type=_path, required=True)
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the environment variable holding the passphrase")
    p.add_argument("--subject", default="CN=AssinaVelox TESTE,O=AssinaVelox,C=BR")
    p.add_argument("--days", type=int, default=365)
    p.add_argument("--out-pem", dest="out_pem", type=_path, default=None, help="also write the certificate as PEM (for --trust)")
    p.set_defaults(func=_cmd_gen_test_cert)

    p = sub.add_parser("cert-info", help="public metadata of the certificate inside a PKCS#12 (no key material, no passphrase)")
    p.add_argument("--pfx", type=_path, required=True, help="PKCS#12 file with key + certificate")
    p.add_argument("--pass-env", dest="pass_env", required=True, help="NAME of the environment variable holding the PKCS#12 passphrase")
    p.set_defaults(func=_cmd_cert_info)

    p = sub.add_parser("selftest", help="end-to-end smoke test in a temporary directory")
    p.add_argument("--keep", action="store_true", help="keep the temporary directory and report its path")
    p.set_defaults(func=_cmd_selftest)

    _add_tsa_commands(sub)
    _add_participant_a1_commands(sub)
    _add_govbr_commands(sub)
    _add_external_commands(sub)
    # P3-LTV (roadmap 3.6): ltv-sign / ltv-refresh / ltv-validate / ltv-gen-test-pki. Additive.
    from pdftool.ltv import add_ltv_commands

    add_ltv_commands(sub, _path)
    return parser


def emit(obj) -> None:
    """Write exactly one UTF-8 JSON line to the real stdout."""
    data = json.dumps(obj, ensure_ascii=False) + "\n"
    out = sys.stdout
    buffer = getattr(out, "buffer", None)
    try:
        if buffer is not None:
            buffer.write(data.encode("utf-8"))
            buffer.flush()
            return
    except (AttributeError, ValueError, OSError):
        pass
    out.write(data)
    out.flush()


def main(argv: Optional[Sequence[str]] = None) -> int:
    # No handler is configured on purpose: Python's "lastResort" handler
    # writes WARNING+ records to whatever sys.stderr is at emit time, which
    # keeps library diagnostics off stdout without binding a stream early.
    logging.getLogger().setLevel(logging.WARNING)
    parser = build_parser()
    try:
        args = parser.parse_args(argv)
        # Any stray print() from a library must not corrupt the JSON contract.
        with contextlib.redirect_stdout(sys.stderr):
            result = args.func(args)
        code = EXIT_OK
    except PdfToolError as exc:
        result, code = exc.to_json(), exc.exit_code
    except SystemExit as exc:  # argparse --version / --help
        return int(exc.code or 0)
    except KeyboardInterrupt:
        result, code = {"ok": False, "error": {"code": "interrupted", "message": "interrupted"}}, EXIT_PROCESSING
    except Exception as exc:  # noqa: BLE001 - last-resort guard: always emit JSON
        traceback.print_exc(file=sys.stderr)
        result = {"ok": False, "error": {"code": "internal_error", "message": f"{type(exc).__name__}: {exc}"}}
        code = EXIT_PROCESSING
    emit(result)
    return code
