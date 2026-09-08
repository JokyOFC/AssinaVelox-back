"""Shared fixtures. All PDF/image fixtures are generated programmatically."""

from __future__ import annotations

import io
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any, Dict, List, Sequence

import pytest
from PIL import Image, ImageDraw
from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject
from reportlab.pdfgen import canvas as rl_canvas

ROOT = Path(__file__).resolve().parents[1]
PYTHON = sys.executable
A4_W, A4_H = 595.2756, 841.8898


def make_pdf(path: Path, pages: Sequence[Dict[str, Any]]) -> Path:
    """Create a PDF with reportlab; optional per-page ``rotate``/``cropbox``/``mediabox`` via pypdf.

    Each page dict may contain: width, height (points), text, rotate (deg),
    cropbox ([x0,y0,x1,y1]) and mediabox ([x0,y0,x1,y1]).
    """
    first = pages[0]
    c = rl_canvas.Canvas(str(path), pagesize=(first.get("width", A4_W), first.get("height", A4_H)))
    for index, spec in enumerate(pages, start=1):
        width, height = spec.get("width", A4_W), spec.get("height", A4_H)
        c.setPageSize((width, height))
        c.setFont("Helvetica", 12)
        c.drawString(72, height - 72, spec.get("text", f"Pagina de teste {index}"))
        c.showPage()
    c.save()

    if any(key in spec for spec in pages for key in ("rotate", "cropbox", "mediabox")):
        reader = PdfReader(str(path))
        writer = PdfWriter(clone_from=reader)
        for index, spec in enumerate(pages):
            page = writer.pages[index]
            if "mediabox" in spec:
                page.mediabox = RectangleObject(spec["mediabox"])
            if "cropbox" in spec:
                page.cropbox = RectangleObject(spec["cropbox"])
            if spec.get("rotate"):
                page.rotate(spec["rotate"])
        buffer = io.BytesIO()
        writer.write(buffer)
        reader.stream.close()
        path.write_bytes(buffer.getvalue())
    return path


def make_png(path: Path, size=(400, 160), transparent: bool = True) -> Path:
    mode = "RGBA" if transparent else "RGB"
    background = (0, 0, 0, 0) if transparent else (255, 255, 255)
    img = Image.new(mode, size, background)
    draw = ImageDraw.Draw(img)
    w, h = size
    colour = (0, 0, 200, 255) if transparent else (0, 0, 200)
    draw.line([(10, h - 20), (w // 3, 20), (2 * w // 3, h - 20), (w - 10, 20)], fill=colour, width=6)
    img.save(path, "PNG")
    return path


def encrypt_pdf(src: Path, dst: Path, user_password: str, owner_password: str, algorithm: str = "AES-128") -> Path:
    reader = PdfReader(str(src))
    writer = PdfWriter(clone_from=reader)
    writer.encrypt(user_password=user_password, owner_password=owner_password, algorithm=algorithm)
    with dst.open("wb") as fh:
        writer.write(fh)
    reader.stream.close()
    return dst


def write_plan(path: Path, source: Path, fields: List[Dict[str, Any]], font: Dict[str, Any] = None) -> Path:
    plan: Dict[str, Any] = {"source": str(source), "fields": fields}
    if font:
        plan["font"] = font
    path.write_text(json.dumps(plan, ensure_ascii=False), encoding="utf-8")
    return path


def run_subprocess(*args, env: Dict[str, str] = None) -> subprocess.CompletedProcess:
    """Invoke ``python -m pdftool`` exactly the way Laravel will (no shell)."""
    full_env = {**os.environ, **(env or {})}
    return subprocess.run(
        [PYTHON, "-m", "pdftool", *map(str, args)],
        cwd=str(ROOT),
        capture_output=True,
        env=full_env,
        timeout=180,
        check=False,
    )


@pytest.fixture
def run_cli(capsys):
    """Run the CLI in-process; assert stdout holds exactly one JSON line; return (exit_code, json)."""
    from pdftool.cli import main

    def _run(*args):
        code = main([str(a) for a in args])
        captured = capsys.readouterr()
        lines = [line for line in captured.out.splitlines() if line.strip()]
        assert len(lines) == 1, f"stdout must contain exactly one JSON line, got: {captured.out!r}"
        return code, json.loads(lines[0])

    return _run


@pytest.fixture
def test_cert(tmp_path, monkeypatch):
    """Self-signed test certificate: returns (pfx_path, pem_path, env_var_name)."""
    from pdftool.certs import generate_test_cert

    env_var = "PDFTOOL_TEST_PASSPHRASE"
    monkeypatch.setenv(env_var, "s3cret-test-pass")
    pfx, pem = tmp_path / "cert.pfx", tmp_path / "cert.pem"
    generate_test_cert(pfx, env_var, days=30, out_pem=pem)
    return pfx, pem, env_var
