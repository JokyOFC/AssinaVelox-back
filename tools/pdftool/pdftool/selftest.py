"""``selftest``: end-to-end smoke test of every command inside a temp directory."""

from __future__ import annotations

import json
import os
import secrets
import shutil
import tempfile
import time
from pathlib import Path
from typing import Any, Callable, Dict, List

from PIL import Image, ImageDraw
from pypdf import PdfReader, PdfWriter
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas as rl_canvas

from pdftool import certs, images, inspect_cmd
from pdftool import compose as compose_mod
from pdftool import sign as sign_mod
from pdftool import validate as validate_mod
from pdftool.errors import ProcessingError

ENV_VAR = "PDFTOOL_SELFTEST_PASSPHRASE"


def _write_pdf(path: Path, labels: List[str], rotate_pages: Dict[int, int]) -> None:
    c = rl_canvas.Canvas(str(path), pagesize=A4)
    for label in labels:
        c.setFont("Helvetica", 14)
        c.drawString(72, 760, label)
        c.showPage()
    c.save()
    if rotate_pages:
        reader = PdfReader(str(path))
        writer = PdfWriter(clone_from=reader)
        for index, angle in rotate_pages.items():
            writer.pages[index].rotate(angle)
        tmp = path.with_suffix(".tmp.pdf")
        with tmp.open("wb") as fh:
            writer.write(fh)
        reader.stream.close()
        tmp.replace(path)


def _write_signature_png(path: Path) -> None:
    img = Image.new("RGBA", (600, 200), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    draw.line([(20, 150), (150, 40), (260, 160), (380, 50), (560, 140)], fill=(20, 40, 160, 255), width=8)
    img.save(path, "PNG")


def _scalars(result: Dict[str, Any]) -> Dict[str, Any]:
    return {k: v for k, v in result.items() if k != "ok" and (v is None or isinstance(v, (str, int, float, bool)))}


def run_selftest(keep: bool = False) -> Dict[str, Any]:
    steps: List[Dict[str, Any]] = []
    tmp = Path(tempfile.mkdtemp(prefix="pdftool-selftest-"))
    passphrase_set = False

    def step(name: str, fn: Callable[[], Dict[str, Any]], extra: Callable[[Dict[str, Any]], Dict[str, Any]] = None) -> Dict[str, Any]:
        started = time.perf_counter()
        result = fn()
        detail = _scalars(result)
        if extra:
            detail.update(extra(result))
        steps.append({"step": name, "ok": True, "ms": round((time.perf_counter() - started) * 1000), **detail})
        return result

    try:
        source = tmp / "source.pdf"
        step(
            "generate_source_pdf",
            lambda: (_write_pdf(source, ["AssinaVelox selftest - pagina 1", "AssinaVelox selftest - pagina 2"], {1: 90}) or {"pages": 2, "rotated_page": 2}),
        )
        sig_png = tmp / "signature.png"
        step("generate_signature_png", lambda: (_write_signature_png(sig_png) or {"path": sig_png.name}))

        plan = {
            "source": str(source),
            "fields": [
                {"id": "sig_1", "page": 1, "type": "signature", "x": 0.10, "y": 0.70, "width": 0.35, "height": 0.10, "image": str(sig_png)},
                {"id": "name_1", "page": 1, "type": "name", "x": 0.10, "y": 0.81, "width": 0.35, "height": 0.04, "value": "João da Silva Conceição", "font_size": 11},
                {"id": "date_1", "page": 1, "type": "date", "x": 0.10, "y": 0.86, "width": 0.35, "height": 0.04, "value": "08/09/2026", "align": "center"},
                {"id": "chk_1", "page": 2, "type": "checkbox", "x": 0.05, "y": 0.05, "width": 0.04, "height": 0.04, "value": True},
                {"id": "txt_1", "page": 2, "type": "text", "x": 0.10, "y": 0.05, "width": 0.50, "height": 0.05, "value": "Li e concordo com os termos (página rotacionada)"},
            ],
        }
        plan_path = tmp / "plan.json"
        plan_path.write_text(json.dumps(plan, ensure_ascii=False), encoding="utf-8")
        composed = tmp / "composed.pdf"
        compose_result = step("compose", lambda: compose_mod.compose(plan_path, composed))
        if compose_result["fields_drawn"] != 5:
            raise ProcessingError("selftest_failed", f"expected 5 fields drawn, got {compose_result['fields_drawn']}")

        extra = tmp / "extra.pdf"
        _write_pdf(extra, ["AssinaVelox selftest - pagina extra"], {})
        appended = tmp / "appended.pdf"
        append_result = step("append", lambda: compose_mod.append_pdfs(composed, extra, appended))
        if append_result["page_count"] != 3:
            raise ProcessingError("selftest_failed", f"expected 3 pages after append, got {append_result['page_count']}")

        step("inspect", lambda: inspect_cmd.inspect_pdf(appended), lambda r: {"page2_rotation": r["pages"][1]["rotation"]})

        step("image2pdf", lambda: images.image_to_pdf(sig_png, tmp / "image.pdf", "a4", 36.0), lambda r: {"page_width_pt": r["pages"][0]["width_pt"]})

        os.environ[ENV_VAR] = secrets.token_urlsafe(24)
        passphrase_set = True
        pfx, pem = tmp / "test.pfx", tmp / "test.pem"
        step("gen_test_cert", lambda: {k: v for k, v in certs.generate_test_cert(pfx, ENV_VAR, certs.DEFAULT_SUBJECT, 30, out_pem=pem).items() if k != "warning"})

        signed = tmp / "signed.pdf"
        step(
            "sign",
            lambda: sign_mod.sign_pdf(appended, signed, pfx, ENV_VAR, field_name="AssinaVelox", reason="selftest", location="BR", visible="1,0.55,0.05,0.40,0.08"),
        )

        def sig_digest(r: Dict[str, Any]) -> Dict[str, Any]:
            first = r["signatures"][0]
            return {k: first[k] for k in ("field_name", "intact", "valid", "trusted", "trust_reason", "coverage", "modification_level")}

        trusted = step("validate_with_trust", lambda: validate_mod.validate_pdf(signed, [pem]), sig_digest)
        first = trusted["signatures"][0]
        if not (first["intact"] and first["valid"] and first["trusted"]):
            raise ProcessingError("selftest_failed", f"signature not intact/valid/trusted: {first['errors']}")
        untrusted = step("validate_without_trust", lambda: validate_mod.validate_pdf(signed, []), sig_digest)
        if untrusted["signatures"][0]["trusted"] is not False or not untrusted["signatures"][0]["intact"]:
            raise ProcessingError("selftest_failed", "validation without trust roots must report intact but untrusted")

        step("inspect_signed", lambda: inspect_cmd.inspect_pdf(signed), lambda r: {"signature_fields": r["signature_fields"]})
    finally:
        if passphrase_set:
            os.environ.pop(ENV_VAR, None)
        if not keep:
            shutil.rmtree(tmp, ignore_errors=True)
    return {"ok": True, "steps": steps, "temp_dir": str(tmp) if keep else None}
