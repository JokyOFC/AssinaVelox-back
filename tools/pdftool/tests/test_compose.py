import math
import re
from pathlib import Path

import pytest
import reportlab
from pypdf import PdfReader

from conftest import make_pdf, make_png, write_plan
from pdftool.geometry import normalized_to_pdf_rect, page_meta_from_pypdf

FIELD = {"x": 0.10, "y": 0.20, "width": 0.30, "height": 0.05}
EXPECTED_DIRECTION = {0: (1, 0), 90: (0, 1), 180: (-1, 0), 270: (0, -1)}


def text_positions(page):
    """(text, x, y, dir_x, dir_y, font_size) of every text run, in user space."""
    found = []

    def visitor(text, cm, tm, _font_dict, font_size):
        if not text or not text.strip():
            return
        # Combined matrix m = Tm x CTM (pypdf convention: [a b c d e f]).
        a = tm[0] * cm[0] + tm[1] * cm[2]
        b = tm[0] * cm[1] + tm[1] * cm[3]
        x = tm[4] * cm[0] + tm[5] * cm[2] + cm[4]
        y = tm[4] * cm[1] + tm[5] * cm[3] + cm[5]
        norm = math.hypot(a, b) or 1.0
        found.append((text.strip(), x, y, a / norm, b / norm, font_size))

    page.extract_text(visitor_text=visitor)
    return found


def inside(x, y, rect, tol=0.5):
    return rect[0] - tol <= x <= rect[2] + tol and rect[1] - tol <= y <= rect[3] + tol


def compose_one(tmp_path, run_cli, page_spec, fields, font=None):
    src = make_pdf(tmp_path / "src.pdf", [page_spec] if isinstance(page_spec, dict) else page_spec)
    plan = write_plan(tmp_path / "plan.json", src, fields, font=font)
    out = tmp_path / "out.pdf"
    code, res = run_cli("compose", "--plan", plan, "--out", out)
    return code, res, src, out


@pytest.mark.parametrize("rotate", [0, 90, 180, 270])
def test_text_lands_inside_rect_and_upright(tmp_path, run_cli, rotate):
    fields = [{"id": "t1", "page": 1, "type": "text", **FIELD, "value": "Olá Mundo"}]
    code, res, src, out = compose_one(tmp_path, run_cli, {"rotate": rotate}, fields)
    assert code == 0, res
    assert res == {"ok": True, "page_count": 1, "fields_drawn": 1, "skipped": []}

    reader = PdfReader(str(out))
    page = reader.pages[0]
    assert page.rotation == rotate  # page boxes and rotation preserved
    meta = page_meta_from_pypdf(page)
    rect = normalized_to_pdf_rect(meta, FIELD["x"], FIELD["y"], FIELD["width"], FIELD["height"])
    hits = [h for h in text_positions(page) if "Mundo" in h[0]]
    assert hits, "composed text not found in output"
    _text, x, y, dx, dy, size = hits[0]
    assert inside(x, y, rect), f"text origin ({x:.1f},{y:.1f}) outside {rect}"
    assert (dx, dy) == pytest.approx(EXPECTED_DIRECTION[rotate], abs=1e-6)
    assert 5 <= size <= 10
    reader.stream.close()


def test_text_with_cropbox_and_mediabox_offset(tmp_path, run_cli):
    spec = {"mediabox": [50, 30, 650, 830], "cropbox": [100, 80, 600, 780], "rotate": 90}
    fields = [{"id": "t1", "page": 1, "type": "name", **FIELD, "value": "Maria Conceição"}]
    code, res, src, out = compose_one(tmp_path, run_cli, spec, fields)
    assert code == 0, res
    reader = PdfReader(str(out))
    page = reader.pages[0]
    assert [float(v) for v in page.mediabox] == [50, 30, 650, 830]
    assert [float(v) for v in page.cropbox] == [100, 80, 600, 780]
    meta = page_meta_from_pypdf(page)
    rect = normalized_to_pdf_rect(meta, FIELD["x"], FIELD["y"], FIELD["width"], FIELD["height"])
    # The rect itself must lie inside the CropBox.
    assert rect[0] >= 100 and rect[1] >= 80 and rect[2] <= 600 and rect[3] <= 780
    hits = [h for h in text_positions(page) if "Maria" in h[0]]
    assert hits
    _t, x, y, dx, dy, _s = hits[0]
    assert inside(x, y, rect)
    assert (dx, dy) == pytest.approx((0, 1), abs=1e-6)
    reader.stream.close()


def test_mediabox_reference_box(tmp_path, run_cli):
    spec = {"cropbox": [100, 100, 500, 700]}
    fields = [{"id": "t1", "page": 1, "type": "text", "x": 0.0, "y": 0.0, "width": 0.3, "height": 0.05, "box": "mediabox", "value": "Canto"}]
    code, res, _src, out = compose_one(tmp_path, run_cli, spec, fields)
    assert code == 0, res
    reader = PdfReader(str(out))
    page = reader.pages[0]
    hits = [h for h in text_positions(page) if "Canto" in h[0]]
    # Relative to the MediaBox the text sits at the very top-left of the page, outside the CropBox.
    assert hits and hits[0][1] < 100 and hits[0][2] > 700
    reader.stream.close()


def test_center_alignment_and_autoshrink(tmp_path, run_cli):
    long_text = "Um texto realmente muito comprido que não cabe na caixa estreita"
    fields = [
        {"id": "left", "page": 1, "type": "text", "x": 0.1, "y": 0.1, "width": 0.4, "height": 0.05, "value": "Esq"},
        {"id": "center", "page": 1, "type": "text", "x": 0.1, "y": 0.2, "width": 0.4, "height": 0.05, "value": "Meio", "align": "center"},
        {"id": "shrink", "page": 1, "type": "text", "x": 0.1, "y": 0.3, "width": 0.15, "height": 0.05, "value": long_text, "font_size": 14},
    ]
    code, res, _src, out = compose_one(tmp_path, run_cli, {}, fields)
    assert code == 0, res
    reader = PdfReader(str(out))
    page = reader.pages[0]
    meta = page_meta_from_pypdf(page)
    hits = {h[0]: h for h in text_positions(page)}
    left_rect = normalized_to_pdf_rect(meta, 0.1, 0.1, 0.4, 0.05)
    center_rect = normalized_to_pdf_rect(meta, 0.1, 0.2, 0.4, 0.05)
    assert hits["Esq"][1] == pytest.approx(left_rect[0] + 2.0, abs=0.01)  # 2 pt padding
    assert hits["Meio"][1] > center_rect[0] + 60  # visibly centred, not left-aligned
    shrink = next(v for k, v in hits.items() if k.startswith("Um texto"))
    assert shrink[5] == 5.0  # shrunk to the 5 pt minimum
    assert inside(shrink[1], shrink[2], normalized_to_pdf_rect(meta, 0.1, 0.3, 0.15, 0.05))
    reader.stream.close()


def test_image_field_present_with_transparency(tmp_path, run_cli):
    png = make_png(tmp_path / "sig.png", transparent=True)
    fields = [
        {"id": "s", "page": 1, "type": "signature", "x": 0.1, "y": 0.6, "width": 0.35, "height": 0.1, "image": str(png)},
        {"id": "i", "page": 1, "type": "initials", "x": 0.6, "y": 0.6, "width": 0.1, "height": 0.05, "image": str(png)},
    ]
    code, res, _src, out = compose_one(tmp_path, run_cli, {"rotate": 90}, fields)
    assert code == 0, res
    assert res["fields_drawn"] == 2
    reader = PdfReader(str(out))
    page = reader.pages[0]
    xobjects = page["/Resources"]["/XObject"]
    images = [xobjects[k].get_object() for k in xobjects if xobjects[k].get_object().get("/Subtype") == "/Image"]
    assert len(images) >= 1
    assert any("/SMask" in img for img in images), "alpha channel should become an /SMask"
    assert len(page.images) >= 1
    reader.stream.close()


def test_checkbox_true_draws_false_skips(tmp_path, run_cli):
    fields = [
        {"id": "yes", "page": 1, "type": "checkbox", "x": 0.1, "y": 0.1, "width": 0.05, "height": 0.05, "value": True},
        {"id": "no", "page": 1, "type": "checkbox", "x": 0.2, "y": 0.1, "width": 0.05, "height": 0.05, "value": False},
        {"id": "str", "page": 1, "type": "checkbox", "x": 0.3, "y": 0.1, "width": 0.05, "height": 0.05, "value": "true"},
    ]
    code, res, src, out = compose_one(tmp_path, run_cli, {}, fields)
    assert code == 0, res
    assert res["fields_drawn"] == 2 and res["skipped"] == ["no"]
    reader = PdfReader(str(out))
    data = reader.pages[0].get_contents().get_data()
    # Square (re), two line segments (l) and a stroke (S); reportlab separates operators with newlines.
    assert re.search(rb"\sre\s", data) and len(re.findall(rb"\sl\s", data)) >= 2 and re.search(rb"\sS\s", data)
    reader.stream.close()


def test_empty_values_skipped(tmp_path, run_cli):
    fields = [
        {"id": "empty", "page": 1, "type": "text", **FIELD, "value": "   "},
        {"id": "none", "page": 1, "type": "date", **FIELD},
        {"id": "ok", "page": 1, "type": "text", **FIELD, "value": "x"},
    ]
    code, res, _src, _out = compose_one(tmp_path, run_cli, {}, fields)
    assert code == 0 and res["fields_drawn"] == 1 and res["skipped"] == ["empty", "none"]


def test_missing_image_exit_4(tmp_path, run_cli):
    fields = [{"id": "s", "page": 1, "type": "signature", **FIELD, "image": str(tmp_path / "nope.png")}]
    code, res, _src, out = compose_one(tmp_path, run_cli, {}, fields)
    assert code == 4 and res["error"]["code"] == "missing_image"
    assert not out.exists()


@pytest.mark.parametrize(
    "field",
    [
        {"id": "bad", "page": 1, "type": "hologram", **FIELD, "value": "x"},
        {"id": "bad", "page": 5, "type": "text", **FIELD, "value": "x"},
        {"id": "bad", "page": 1, "type": "text", "x": 0.9, "y": 0.2, "width": 0.3, "height": 0.05, "value": "x"},
        {"id": "bad", "page": 1, "type": "checkbox", **FIELD, "value": "maybe"},
        {"id": "bad", "page": 0, "type": "text", **FIELD, "value": "x"},
    ],
)
def test_invalid_plan_exit_2(tmp_path, run_cli, field):
    code, res, _src, _out = compose_one(tmp_path, run_cli, {}, [field])
    assert code == 2 and res["error"]["code"] == "invalid_plan"
    assert "bad" in res["error"]["message"]


def test_multi_page_untouched_pages_identical(tmp_path, run_cli):
    fields = [
        {"id": "p1", "page": 1, "type": "text", **FIELD, "value": "um"},
        {"id": "p3", "page": 3, "type": "text", **FIELD, "value": "três"},
    ]
    code, res, src, out = compose_one(tmp_path, run_cli, [{}, {"rotate": 90}, {}], fields)
    assert code == 0 and res["page_count"] == 3 and res["fields_drawn"] == 2
    src_reader, out_reader = PdfReader(str(src)), PdfReader(str(out))
    assert out_reader.pages[1].get_contents().get_data() == src_reader.pages[1].get_contents().get_data()
    assert out_reader.pages[1].rotation == 90
    assert "três" in out_reader.pages[2].extract_text()
    src_reader.stream.close()
    out_reader.stream.close()


def test_custom_ttf_font(tmp_path, run_cli):
    vera = Path(reportlab.__file__).parent / "fonts" / "Vera.ttf"
    if not vera.is_file():
        pytest.skip("reportlab Vera.ttf not shipped")
    fields = [{"id": "t", "page": 1, "type": "text", **FIELD, "value": "Ação — coração"}]
    code, res, _src, out = compose_one(tmp_path, run_cli, {}, fields, font={"path": str(vera), "name": "VeraTest"})
    assert code == 0, res
    reader = PdfReader(str(out))
    fonts = reader.pages[0]["/Resources"]["/Font"]
    base_fonts = [str(fonts[k].get_object()["/BaseFont"]) for k in fonts]
    # reportlab embeds a subset named after the TTF's internal name (e.g. AAAAAA+BitstreamVeraSans-Roman).
    assert any("Vera" in name and "+" in name for name in base_fonts), base_fonts
    assert "coração" in reader.pages[0].extract_text()
    reader.stream.close()


def test_same_path_rejected(tmp_path, run_cli):
    src = make_pdf(tmp_path / "src.pdf", [{}])
    plan = write_plan(tmp_path / "plan.json", src, [{"id": "t", "page": 1, "type": "text", **FIELD, "value": "x"}])
    code, res = run_cli("compose", "--plan", plan, "--out", src)
    assert code == 2 and res["error"]["code"] == "same_path"


def test_missing_source_exit_4(tmp_path, run_cli):
    plan = write_plan(tmp_path / "plan.json", tmp_path / "missing.pdf", [])
    code, res = run_cli("compose", "--plan", plan, "--out", tmp_path / "out.pdf")
    assert code == 4 and res["error"]["code"] == "missing_source"
