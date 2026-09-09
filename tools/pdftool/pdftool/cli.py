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
    )


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
