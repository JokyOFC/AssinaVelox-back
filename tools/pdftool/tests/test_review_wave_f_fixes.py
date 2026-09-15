"""Adversarial review, wave F — regression tests for the fixes in ``anchors.py``.

- a page with more glyphs than ``MAX_GLYPHS_PER_PAGE`` is refused (``too_many_glyphs``);
- the OCR of a page never gets a tesseract timeout larger than what is left of the whole-search
  budget (the PHP process timeout is budget + page timeout + margin, so python is never killed
  in the middle of a tesseract run).
"""

from __future__ import annotations

from pathlib import Path

import pytest
from reportlab.pdfgen import canvas as rl_canvas

from pdftool import anchors
from pdftool.anchors import OcrOptions, Spec, find_anchors
from pdftool.errors import InputRejected


def _line(path: Path, glyphs: int) -> Path:
    c = rl_canvas.Canvas(str(path), pagesize=(595, 842))
    text = c.beginText()
    text.setFont("Helvetica", 0.5)
    text.setTextOrigin(10, 400)
    text.textOut("a" * glyphs)
    c.drawText(text)
    c.save()
    return path


def _blank(path: Path) -> Path:
    c = rl_canvas.Canvas(str(path), pagesize=(595, 842))
    c.rect(100, 100, 200, 200)
    c.showPage()
    c.save()
    return path


def test_page_with_too_many_glyphs_is_refused(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(anchors, "MAX_GLYPHS_PER_PAGE", 100)
    pdf = _line(tmp_path / "dense.pdf", 300)

    with pytest.raises(InputRejected) as caught:
        find_anchors(pdf, Spec())

    assert caught.value.code == "too_many_glyphs"


def test_dense_line_groups_in_linear_time(tmp_path: Path) -> None:
    pdf = _line(tmp_path / "ok.pdf", 400)

    result = find_anchors(pdf, Spec())

    assert result["pages"][0]["text_chars"] == 400


def test_ocr_timeout_never_exceeds_the_remaining_budget(tmp_path: Path) -> None:
    pdf = _blank(tmp_path / "scan.pdf")
    seen: list = []

    def fake(binary, image, lang, timeout):  # noqa: ARG001 - runner signature
        seen.append(timeout)
        return "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"

    find_anchors(pdf, Spec(), time_budget=5.0, ocr=OcrOptions(tesseract="fake", runner=fake, dpi=72, timeout=60.0))

    assert len(seen) == 1
    assert 1.0 <= seen[0] <= 5.0
