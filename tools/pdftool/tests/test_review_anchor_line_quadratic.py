"""Adversarial review, wave F (untrusted input): ``find-anchors`` ignores its time budget inside a page.

``--time-budget`` is documented as "seconds for the whole search", but ``find_anchors`` only
checks the clock BETWEEN pages. Inside one page, ``text_units`` groups glyphs into lines by
recomputing ``sum(x.across for x in current) / len(current)`` for every glyph added to the
current line, so a page whose glyphs share one baseline costs O(n^2): measured here, 4,000
glyphs = 1.35 s, 8,000 = 4.6 s, 16,000 = 19.7 s. A one-page PDF with ~50,000 tiny glyphs on one
line (a few hundred KB) runs past the PHP process timeout (90 s by default, and the synchronous
"Testar regras" endpoint waits for it inside an HTTP request) instead of stopping at the pdftool
budget with ``time_budget_exceeded``.

The test gives a 1 s budget to a single page with 12,000 glyphs on one line: the search must
either finish quickly or stop with ``ProcessingError`` close to the budget.
"""

from __future__ import annotations

import time
from pathlib import Path

from reportlab.pdfgen import canvas as rl_canvas

from pdftool.anchors import Spec, find_anchors
from pdftool.errors import ProcessingError


def _one_dense_line(path: Path, glyphs: int) -> Path:
    c = rl_canvas.Canvas(str(path), pagesize=(595, 842))
    text = c.beginText()
    text.setFont("Helvetica", 0.02)  # every glyph stays inside the CropBox
    text.setTextOrigin(10, 400)
    text.textOut("a" * glyphs)
    c.drawText(text)
    c.save()
    return path


def test_time_budget_holds_inside_a_single_dense_page(tmp_path: Path) -> None:
    pdf = _one_dense_line(tmp_path / "dense.pdf", 12_000)

    started = time.monotonic()
    try:
        result = find_anchors(pdf, Spec(), time_budget=1.0)
        assert result["pages"][0]["text_chars"] == 12_000
    except ProcessingError:
        pass
    elapsed = time.monotonic() - started

    assert elapsed < 4.0, f"search took {elapsed:.1f}s with a 1s budget"
