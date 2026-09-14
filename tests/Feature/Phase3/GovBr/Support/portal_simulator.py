"""Test-only SIMULATOR of what a signing portal would return (P3-GOV).

NOT the gov.br portal and not a model of it: the real output of assinador.iti.br is NOT
CONFIRMED (docs/integracoes/gov-br-assinatura.md 6.1). This produces, with a TEST certificate
(throw-away TEST CA, CN with "TESTE"), the shapes the acceptance rule has to handle.

Run with the pdftool venv and cwd = tools/pdftool (so ``pdftool`` is importable):

    python portal_simulator.py --mode sign --in base.pdf --out devolvido.pdf --pfx cert.pfx --pass-env VAR

Modes: sign | visible | two | append-after | alter | rewrite-then-sign | certify-p1 | certify-p2.
Prints one JSON line.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

from pypdf import PdfReader, PdfWriter
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import fields, signers
from pyhanko.sign.fields import MDPPerm

from pdftool.inspect_cert import load_participant_pkcs12
from pdftool.participant_sign import _signer_from


def _sign(src: Path, dst: Path, signer, field: str, certify=None, alter=False, visible=False) -> None:
    with src.open("rb") as inf:
        writer = IncrementalPdfFileWriter(inf, strict=False)
        if alter:
            stream = generic.StreamObject(stream_data=b"BT /F1 24 Tf 72 400 Td (VALOR ALTERADO) Tj ET")
            writer.add_stream_to_page(0, writer.add_object(stream))
        meta = signers.PdfSignatureMetadata(
            field_name=field,
            md_algorithm="sha256",
            subfilter=fields.SigSeedSubFilter.PADES,
            certify=certify is not None,
            docmdp_permissions=MDPPerm(certify) if certify is not None else MDPPerm.FILL_FORMS,
        )
        spec = fields.SigFieldSpec(sig_field_name=field, box=(72, 72, 272, 122) if visible else None)
        with dst.open("wb") as outf:
            signers.PdfSigner(meta, signer, new_field_spec=spec).sign_pdf(writer, output=outf)


def _append(src: Path, dst: Path) -> None:
    with src.open("rb") as inf:
        writer = IncrementalPdfFileWriter(inf, strict=False)
        writer.root["/AVDepois"] = generic.NameObject("/Alterado")
        writer.update_root()
        with dst.open("wb") as outf:
            writer.write(outf)


def _rewrite(src: Path, dst: Path) -> None:
    reader = PdfReader(str(src))
    writer = PdfWriter(clone_from=reader)
    with dst.open("wb") as outf:
        writer.write(outf)
    reader.stream.close()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", required=True, choices=[
        "sign", "visible", "two", "append-after", "alter", "rewrite-then-sign", "certify-p1", "certify-p2",
    ])
    parser.add_argument("--in", dest="input", required=True)
    parser.add_argument("--out", dest="output", required=True)
    parser.add_argument("--pfx", required=True)
    parser.add_argument("--pass-env", dest="pass_env", required=True)
    parser.add_argument("--second-pfx", dest="second_pfx", default=None)
    parser.add_argument("--second-pass-env", dest="second_pass_env", default=None)
    args = parser.parse_args()

    src, dst = Path(args.input), Path(args.output)
    tmp = dst.with_suffix(".tmp.pdf")
    signer = _signer_from(load_participant_pkcs12(Path(args.pfx), os.environ[args.pass_env]))

    try:
        if args.mode == "sign":
            _sign(src, dst, signer, "GovBr_Assinatura")
        elif args.mode == "visible":
            _sign(src, dst, signer, "GovBr_Assinatura", visible=True)
        elif args.mode == "alter":
            _sign(src, dst, signer, "GovBr_Assinatura", alter=True)
        elif args.mode == "certify-p1":
            _sign(src, dst, signer, "GovBr_Assinatura", certify=1)
        elif args.mode == "certify-p2":
            _sign(src, dst, signer, "GovBr_Assinatura", certify=2)
        elif args.mode == "two":
            second = _signer_from(load_participant_pkcs12(Path(args.second_pfx or args.pfx), os.environ[args.second_pass_env or args.pass_env]))
            _sign(src, tmp, signer, "GovBr_1")
            _sign(tmp, dst, second, "GovBr_2")
        elif args.mode == "append-after":
            _sign(src, tmp, signer, "GovBr_Assinatura")
            _append(tmp, dst)
        elif args.mode == "rewrite-then-sign":
            _rewrite(src, tmp)
            _sign(tmp, dst, signer, "GovBr_Assinatura")
    except Exception as exc:  # noqa: BLE001 - test helper: report and fail
        print(json.dumps({"ok": False, "error": f"{type(exc).__name__}: {exc}"}))
        return 1
    finally:
        tmp.unlink(missing_ok=True)

    print(json.dumps({"ok": True, "out": str(dst), "size": dst.stat().st_size}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
