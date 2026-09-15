"""find-anchors (roadmap 3.2): markers, literals, rotations, limits and the OCR path.

Every fixture is generated here with reportlab (+ pypdf for /Rotate, CropBox and MediaBox).
The geometry proof is a round trip: the normalized box returned by find-anchors, converted
back with ``geometry.normalized_to_pdf_rect`` (the function mirrored by the PHP
``FieldGeometry::toPdfRect``), must cover the user-space point where reportlab drew the text.
"""

from __future__ import annotations

import io
import json
import subprocess
import sys
from pathlib import Path
from typing import Dict, List, Optional, Sequence

import pytest
from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject
from reportlab.pdfgen import canvas as rl_canvas

from pdftool.anchors import (
    OcrOptions,
    OcrPageError,
    Spec,
    find_anchors,
    normalize_text,
    parse_spec,
    parse_tsv,
    user_to_displayed,
)
from pdftool.errors import InputRejected, ProcessingError, UsageError
from pdftool.geometry import PageMeta, displayed_to_user, normalized_to_pdf_rect, page_meta_from_pypdf

ROOT = Path(__file__).resolve().parents[1]
PYTHON = sys.executable
W, H = 600.0, 800.0


def build_pdf(path: Path, pages: Sequence[Dict]) -> Path:
    """pages: [{"texts": [(x, y, "text", size)], "rotate": 90, "cropbox": [...], "mediabox": [...], "box": True}]"""
    buf = io.BytesIO()
    c = rl_canvas.Canvas(buf, pagesize=(W, H))
    for spec in pages:
        c.setPageSize((spec.get("width", W), spec.get("height", H)))
        for x, y, text, *rest in spec.get("texts", []):
            c.setFont("Helvetica", rest[0] if rest else 12)
            c.drawString(x, y, text)
        if spec.get("box"):
            c.rect(100, 100, 300, 200, stroke=1, fill=1)
        c.showPage()
    c.save()
    reader = PdfReader(io.BytesIO(buf.getvalue()))
    writer = PdfWriter(clone_from=reader)
    for index, spec in enumerate(pages):
        page = writer.pages[index]
        if "mediabox" in spec:
            page.mediabox = RectangleObject(spec["mediabox"])
        if "cropbox" in spec:
            page.cropbox = RectangleObject(spec["cropbox"])
        if spec.get("rotate"):
            page.rotate(spec["rotate"])
    with path.open("wb") as fh:
        writer.write(fh)
    return path


def meta_of(path: Path, page: int) -> PageMeta:
    reader = PdfReader(str(path))
    try:
        return page_meta_from_pypdf(reader.pages[page - 1])
    finally:
        reader.stream.close()


def back_to_user(path: Path, match: Dict) -> tuple:
    b = match["box"]
    return normalized_to_pdf_rect(meta_of(path, match["page"]), b["x"], b["y"], b["width"], b["height"])


def contains(rect, x, y, slack=0.5) -> bool:
    return rect[0] - slack <= x <= rect[2] + slack and rect[1] - slack <= y <= rect[3] + slack


def cli(*args: str):
    proc = subprocess.run([PYTHON, "-m", "pdftool", *args], cwd=str(ROOT), capture_output=True)
    lines = [line for line in proc.stdout.decode("utf-8").splitlines() if line.strip()]
    assert len(lines) == 1, proc.stdout
    return proc.returncode, json.loads(lines[0])


MARKER = "{{assinatura:comprador}}"


# -- Pure geometry ----------------------------------------------------------------------


@pytest.mark.parametrize("rotation", [0, 90, 180, 270])
@pytest.mark.parametrize("crop", [(0.0, 0.0, 600.0, 800.0), (50.0, 30.0, 520.0, 770.0)])
def test_user_to_displayed_is_the_inverse_of_displayed_to_user(rotation, crop):
    meta = PageMeta((0.0, 0.0, 600.0, 800.0), crop, rotation)
    disp_w, disp_h = meta.displayed_size
    for dx, dy in [(0, 0), (10, 20), (disp_w, disp_h), (disp_w / 3, disp_h / 5)]:
        ux, uy = displayed_to_user(meta, dx, dy)
        assert user_to_displayed(meta, ux, uy) == pytest.approx((dx, dy), abs=1e-9)


# -- Markers on the four rotations ------------------------------------------------------


@pytest.mark.parametrize("rotation", [0, 90, 180, 270])
def test_marker_box_round_trips_to_where_the_text_was_drawn(tmp_path, rotation):
    pdf = build_pdf(tmp_path / f"r{rotation}.pdf", [{"texts": [(100, 700, MARKER)], "rotate": rotation}])

    result = find_anchors(pdf, Spec())

    assert result["page_count"] == 1
    assert result["pages"][0]["rotation"] == rotation
    [match] = result["matches"]
    assert match["kind"] == "marker"
    assert match["field_type"] == "signature"
    assert match["key"] == "comprador"
    assert match["source"] == "text"

    rect = back_to_user(pdf, match)
    # Baseline start of the marker and a point inside the first glyph.
    assert contains(rect, 101.0, 703.0)
    # The box hugs the text: ~12 pt tall along user y, starts at x=100.
    assert rect[0] == pytest.approx(100.0, abs=1.0)
    assert 9.0 <= rect[3] - rect[1] <= 16.0
    assert rect[2] - rect[0] > 100.0

    # Displayed size is swapped on 90/270 and the box is inside [0, 1].
    box = match["box"]
    assert 0.0 <= box["x"] <= 1.0 and 0.0 <= box["y"] <= 1.0
    assert box["x"] + box["width"] <= 1.0 + 1e-9 and box["y"] + box["height"] <= 1.0 + 1e-9
    if rotation in (90, 270):
        assert result["pages"][0]["width_pt"] == pytest.approx(800.0)
        # Rotated text is displayed vertically: taller than wide.
        assert box["height"] * 600 > box["width"] * 800


@pytest.mark.parametrize("rotation", [0, 90, 180, 270])
def test_cropbox_offset_is_respected(tmp_path, rotation):
    crop = [50, 60, 550, 760]
    pdf = build_pdf(tmp_path / "crop.pdf", [{"texts": [(120, 600, MARKER)], "rotate": rotation, "cropbox": crop}])

    [match] = find_anchors(pdf, Spec())["matches"]

    rect = back_to_user(pdf, match)
    assert contains(rect, 121.0, 603.0)
    assert rect[0] == pytest.approx(120.0, abs=1.0)


@pytest.mark.parametrize("rotation", [0, 90])
def test_mediabox_offset_is_respected(tmp_path, rotation):
    pdf = build_pdf(
        tmp_path / "media.pdf",
        [{"texts": [(250, 500, MARKER)], "rotate": rotation, "mediabox": [100, 100, 700, 900]}],
    )

    [match] = find_anchors(pdf, Spec())["matches"]

    assert contains(back_to_user(pdf, match), 251.0, 503.0)


def test_text_outside_the_cropbox_is_ignored(tmp_path):
    pdf = build_pdf(tmp_path / "outside.pdf", [{"texts": [(20, 20, MARKER), (100, 400, "{{rubrica:vendedor}}")], "cropbox": [50, 50, 550, 750]}])

    matches = find_anchors(pdf, Spec())["matches"]

    assert [m["key"] for m in matches] == ["vendedor"]
    assert matches[0]["field_type"] == "initials"


# -- Occurrences, grammar and literals ----------------------------------------------------


def test_several_occurrences_on_several_pages(tmp_path):
    pdf = build_pdf(
        tmp_path / "many.pdf",
        [
            {"texts": [(72, 700, "{{assinatura:locador}}"), (72, 600, "{{data:locador}}"), (320, 700, "{{assinatura:locatario}}")]},
            {"texts": [(72, 700, "{{rubrica:locatario}}"), (72, 500, "{{texto:observacoes}}"), (72, 300, "{{assinatura:testemunha 1}}")]},
        ],
    )

    result = find_anchors(pdf, Spec())

    assert result["match_count"] == 6
    got = sorted((m["page"], m["field_type"], m["key"]) for m in result["matches"])
    assert got == [
        (1, "date", "locador"),
        (1, "signature", "locador"),
        (1, "signature", "locatario"),
        (2, "initials", "locatario"),
        (2, "signature", "testemunha_1"),
        (2, "text", "observacoes"),
    ]
    # Sorted by page, then top to bottom.
    assert [m["page"] for m in result["matches"]] == [1, 1, 1, 2, 2, 2]


def test_marker_grammar_ignores_case_accents_and_spaces(tmp_path):
    pdf = build_pdf(tmp_path / "case.pdf", [{"texts": [(72, 700, "{{ Assinatura : Comprador Principal }}"), (72, 600, "{{RUBRICA:vendedor}}")]}])

    keys = sorted(m["key"] for m in find_anchors(pdf, Spec())["matches"])

    assert keys == ["comprador_principal", "vendedor"]


def test_unknown_or_malformed_markers_are_not_matches(tmp_path):
    pdf = build_pdf(
        tmp_path / "bad.pdf",
        [{"texts": [(72, 700, "{{assinaturas:x}}"), (72, 650, "{{assinatura:}}"), (72, 600, "{assinatura:x}"), (72, 550, "{{carimbo:x}}")]}],
    )

    assert find_anchors(pdf, Spec())["matches"] == []


def test_marker_broken_across_two_lines_is_one_match(tmp_path):
    pdf = build_pdf(tmp_path / "broken.pdf", [{"texts": [(72, 700, "Contratante: {{assinatura:"), (72, 686, "comprador}} fim.")]}])

    [match] = find_anchors(pdf, Spec())["matches"]

    assert match["key"] == "comprador"
    assert match["lines"] == 2
    # line_box covers only the first line; box covers both.
    assert match["line_box"]["height"] < match["box"]["height"]
    rect = back_to_user(pdf, match)
    assert contains(rect, 150.0, 703.0) and contains(rect, 73.0, 689.0)


def test_literal_search_normalizes_spaces_case_and_accents(tmp_path):
    pdf = build_pdf(
        tmp_path / "lit.pdf",
        [{"texts": [(72, 700, "ASSINATURA   DO LOCATARIO"), (72, 600, "Assinatura do locatário:"), (72, 500, "Assinaturas do locatários")]}],
    )
    spec = parse_spec({"markers": False, "literals": [{"id": "loc", "text": "Assinatura do Locatário"}]})

    matches = find_anchors(pdf, spec)["matches"]

    # The third line is not a whole-word match.
    assert len(matches) == 2
    assert all(m["kind"] == "literal" and m["literal_id"] == "loc" and m["field_type"] is None for m in matches)


def test_literal_is_never_a_regular_expression(tmp_path):
    pdf = build_pdf(tmp_path / "regex.pdf", [{"texts": [(72, 700, "axxxb"), (72, 600, "valor a.*b aqui")]}])
    spec = parse_spec({"markers": False, "literals": [{"id": "r", "text": "a.*b"}]})

    matches = find_anchors(pdf, spec)["matches"]

    assert len(matches) == 1
    assert contains(back_to_user(pdf, matches[0]), 110.0, 603.0, slack=20)


def test_output_never_echoes_document_text(tmp_path):
    pdf = build_pdf(tmp_path / "secret.pdf", [{"texts": [(72, 700, "CONFIDENCIAL-XYZ " + MARKER), (72, 650, "<script>alert(1)</script>")]}])

    code, payload = cli("find-anchors", "--in", str(pdf))

    assert code == 0 and payload["ok"] is True
    raw = json.dumps(payload)
    assert "CONFIDENCIAL" not in raw and "script" not in raw and "alert" not in raw
    assert payload["matches"][0]["key"] == "comprador"


def test_page_without_text_is_reported(tmp_path):
    pdf = build_pdf(tmp_path / "scan.pdf", [{"texts": [(72, 700, MARKER)]}, {"box": True}, {"texts": [(72, 700, "ok")]}])

    result = find_anchors(pdf, Spec())

    assert result["pages_without_text"] == [2, 3]
    assert [p["has_text"] for p in result["pages"]] == [True, False, False]
    assert result["pages"][1]["text_chars"] == 0
    assert result["pages"][1]["ocr"] == "not_requested"
    assert result["pages"][0]["ocr"] == "not_needed"
    assert result["ocr"] is None


# -- Rejections and limits ----------------------------------------------------------------


def test_encrypted_pdf_is_rejected(tmp_path):
    plain = build_pdf(tmp_path / "plain.pdf", [{"texts": [(72, 700, MARKER)]}])
    reader = PdfReader(str(plain))
    writer = PdfWriter(clone_from=reader)
    writer.encrypt(user_password="", owner_password="dono", algorithm="AES-128")
    encrypted = tmp_path / "enc.pdf"
    with encrypted.open("wb") as fh:
        writer.write(fh)
    reader.stream.close()

    code, payload = cli("find-anchors", "--in", str(encrypted))

    assert code == 4
    assert payload["error"]["code"] == "encrypted_pdf"


def test_invalid_and_missing_pdf_are_rejected(tmp_path):
    bad = tmp_path / "bad.pdf"
    bad.write_bytes(b"%PDF-1.7\nnot really a pdf")

    code, payload = cli("find-anchors", "--in", str(bad))
    assert code == 4 and payload["error"]["code"] == "invalid_pdf"

    code, payload = cli("find-anchors", "--in", str(tmp_path / "missing.pdf"))
    assert code == 4 and payload["error"]["code"] == "missing_input"


def test_page_and_size_limits(tmp_path):
    pdf = build_pdf(tmp_path / "two.pdf", [{"texts": [(72, 700, MARKER)]}, {"texts": [(72, 700, MARKER)]}])

    code, payload = cli("find-anchors", "--in", str(pdf), "--max-pages", "1")
    assert code == 4 and payload["error"]["code"] == "too_many_pages"

    code, payload = cli("find-anchors", "--in", str(pdf), "--max-bytes", "1024")
    assert code == 4 and payload["error"]["code"] == "pdf_too_large"

    code, payload = cli("find-anchors", "--in", str(pdf), "--max-pages", "0")
    assert code == 2 and payload["error"]["code"] == "usage_error"


def test_time_budget_is_enforced(tmp_path):
    pdf = build_pdf(tmp_path / "slow.pdf", [{"texts": [(72, 700, MARKER)]}] * 3)
    ticks = iter([0.0, 0.0, 5.0, 50.0, 100.0, 200.0])

    with pytest.raises(ProcessingError) as error:
        find_anchors(pdf, Spec(), time_budget=10.0, clock=lambda: next(ticks))

    assert error.value.code == "time_budget_exceeded"


def test_match_limit_truncates(tmp_path):
    pdf = build_pdf(tmp_path / "lots.pdf", [{"texts": [(72, 780 - 20 * i, "{{assinatura:p%d}}" % i) for i in range(10)]}])

    result = find_anchors(pdf, parse_spec({"max_matches": 3}))

    assert result["match_count"] == 3
    assert result["truncated"] is True


@pytest.mark.parametrize(
    "spec",
    [
        [],
        {"markers": "sim"},
        {"markers": False},
        {"literals": [{"id": "x y", "text": "abc"}]},
        {"literals": [{"id": "a", "text": "x"}]},
        {"literals": [{"id": "a", "text": "abc"}, {"id": "a", "text": "def"}]},
        {"literals": [{"id": "l%d" % i, "text": "texto %d" % i} for i in range(51)]},
        {"literals": [{"id": "a", "text": "x" * 121}]},
        {"max_matches": 0},
    ],
)
def test_invalid_specs_are_usage_errors(spec):
    with pytest.raises(UsageError) as error:
        parse_spec(spec)
    assert error.value.code == "invalid_spec"


def test_invalid_spec_file_via_cli(tmp_path):
    pdf = build_pdf(tmp_path / "a.pdf", [{"texts": [(72, 700, MARKER)]}])
    spec = tmp_path / "spec.json"
    spec.write_text("{nope", encoding="utf-8")

    code, payload = cli("find-anchors", "--in", str(pdf), "--spec", str(spec))
    assert code == 2 and payload["error"]["code"] == "invalid_spec"

    spec.write_text(json.dumps({"literals": [{"id": "l1", "text": "Assinatura"}]}), encoding="utf-8")
    code, payload = cli("find-anchors", "--in", str(pdf), "--spec", str(spec))
    assert code == 0 and payload["match_count"] == 2  # the marker + "assinatura" inside it


def test_normalize_text():
    assert normalize_text("  Assinatura\t do\nLOCATÁRIO ") == "assinatura do locatario"
    assert normalize_text("ﬁrma") == "firma"


# -- OCR --------------------------------------------------------------------------------

TSV_HEADER = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"


def tsv(words: List[tuple]) -> str:
    rows = [TSV_HEADER, "1\t1\t0\t0\t0\t0\t0\t0\t1000\t1000\t-1\t"]
    for block, line, left, top, width, height, conf, text in words:
        rows.append(f"5\t1\t{block}\t1\t{line}\t1\t{left}\t{top}\t{width}\t{height}\t{conf}\t{text}")
    return "\n".join(rows) + "\n"


class FakeTesseract:
    """Stands in for the binary: returns canned TSV scaled to the rendered image."""

    def __init__(self, words_fn, fail: Optional[str] = None):
        self.words_fn = words_fn
        self.fail = fail
        self.calls: List[tuple] = []

    def __call__(self, binary, image: Path, lang, timeout):
        from PIL import Image

        with Image.open(image) as img:
            size = img.size
        self.calls.append((binary, image.name, lang, timeout, size))
        if self.fail:
            raise OcrPageError(self.fail)
        return tsv(self.words_fn(*size))


@pytest.mark.parametrize("rotation", [0, 90])
def test_ocr_words_become_normalized_matches(tmp_path, rotation):
    pdf = build_pdf(tmp_path / "scan.pdf", [{"texts": [(72, 700, MARKER)]}, {"box": True, "rotate": rotation}])
    # Marker drawn by "OCR" at 10%..50% x, 20%..23% y of the DISPLAYED page.
    fake = FakeTesseract(lambda w, h: [(1, 1, int(w * 0.1), int(h * 0.2), int(w * 0.4), int(h * 0.03), 91.5, "{{assinatura:locador}}")])

    result = find_anchors(pdf, Spec(), ocr=OcrOptions(tesseract="fake", runner=fake, dpi=72))

    assert len(fake.calls) == 1
    binary, image_name, lang, timeout, size = fake.calls[0]
    assert (lang, image_name) == ("por", "page-2.png")
    # pypdfium2 renders the page as displayed: landscape for /Rotate 90.
    assert (size[0] > size[1]) == (rotation == 90)

    ocr_matches = [m for m in result["matches"] if m["source"] == "ocr"]
    [match] = ocr_matches
    assert match["page"] == 2 and match["key"] == "locador" and match["confidence"] == 91.5
    assert match["box"]["x"] == pytest.approx(0.1, abs=0.01)
    assert match["box"]["y"] == pytest.approx(0.2, abs=0.01)
    assert match["box"]["width"] == pytest.approx(0.4, abs=0.01)
    assert result["pages"][1]["ocr"] == "done"
    assert result["ocr"]["pages_done"] == 1 and result["ocr"]["engine"] == "tesseract"


def test_ocr_literal_split_over_words_and_lines(tmp_path):
    pdf = build_pdf(tmp_path / "scan.pdf", [{"box": True}])
    fake = FakeTesseract(
        lambda w, h: [
            (1, 1, 100, 100, 80, 20, 90, "Assinatura"),
            (1, 1, 190, 100, 30, 20, 85, "do"),
            (1, 2, 100, 130, 90, 20, 70, "LOCATÁRIO"),
            (2, 1, 600, 100, 80, 20, 90, "Assinatura"),
        ]
    )
    spec = parse_spec({"markers": False, "literals": [{"id": "loc", "text": "assinatura do locatario"}]})

    [match] = find_anchors(pdf, spec, ocr=OcrOptions(tesseract="fake", runner=fake, dpi=72))["matches"]

    assert match["literal_id"] == "loc" and match["lines"] == 2 and match["confidence"] == 70.0


def test_ocr_failure_on_a_page_is_reported_not_fatal(tmp_path):
    pdf = build_pdf(tmp_path / "scan.pdf", [{"texts": [(72, 700, MARKER)]}, {"box": True}])

    result = find_anchors(pdf, Spec(), ocr=OcrOptions(tesseract="fake", runner=FakeTesseract(lambda w, h: [], fail="timeout"), dpi=72))

    assert result["ok"] is True
    assert len(result["matches"]) == 1  # the text-layer marker still comes back
    assert result["pages"][1]["ocr"] == "failed" and result["pages"][1]["ocr_error"] == "timeout"
    assert result["ocr"]["pages_failed"] == 1


def test_ocr_page_limits(tmp_path):
    pdf = build_pdf(tmp_path / "scan.pdf", [{"box": True}, {"box": True}, {"box": True}])
    fake = FakeTesseract(lambda w, h: [])

    result = find_anchors(pdf, Spec(), ocr=OcrOptions(tesseract="fake", runner=fake, dpi=72, max_pages=1, pages=[2, 3]))

    assert [c[1] for c in fake.calls] == ["page-2.png"]
    assert [p["ocr"] for p in result["pages"]] == ["skipped", "done", "skipped"]
    assert result["ocr"]["pages_skipped"] == 2


def test_ocr_without_binary_is_a_clear_error(tmp_path):
    pdf = build_pdf(tmp_path / "scan.pdf", [{"box": True}])

    code, payload = cli("find-anchors", "--in", str(pdf), "--ocr", "--tesseract", str(tmp_path / "nao-existe.exe"))
    assert code == 2 and payload["error"]["code"] == "ocr_unavailable"
    assert str(tmp_path) not in payload["error"]["message"]

    code, payload = cli("find-anchors", "--in", str(pdf), "--ocr")
    assert code == 2 and payload["error"]["code"] == "ocr_unavailable"


def test_ocr_language_is_validated(tmp_path):
    pdf = build_pdf(tmp_path / "scan.pdf", [{"box": True}])
    with pytest.raises(UsageError):
        find_anchors(pdf, Spec(), ocr=OcrOptions(tesseract="fake", runner=FakeTesseract(lambda w, h: []), lang="por; rm -rf"))


def test_parse_tsv_skips_noise():
    words = parse_tsv(
        tsv([(1, 1, 10, 10, 50, 10, 95, "ok"), (1, 1, 70, 10, 50, 10, -1, "ruido"), (1, 1, 0, 0, 0, 10, 90, "zero")]) + "lixo\tsem\tcolunas\n",
        200,
        100,
    )
    assert [w[0] for w in words] == ["ok"]
    assert words[0][1] == pytest.approx((0.05, 0.1, 0.3, 0.2))
    assert parse_tsv("", 10, 10) == [] and parse_tsv(TSV_HEADER, 0, 10) == []
