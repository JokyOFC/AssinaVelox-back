"""Pure geometry tests: four rotations plus CropBox/MediaBox offsets."""

import pytest

from pdftool.geometry import (
    PageMeta,
    displayed_to_user,
    field_frame,
    normalize_rect,
    normalize_rotation,
    normalized_to_pdf_rect,
)

MEDIA = (0.0, 0.0, 600.0, 800.0)
# Field used everywhere: x=0.10, y=0.20, w=0.30, h=0.05 (top-left origin, normalized).
FIELD = (0.10, 0.20, 0.30, 0.05)


def _approx(rect, expected):
    assert rect == pytest.approx(expected, abs=1e-6)


def test_rotation_0():
    meta = PageMeta(MEDIA, MEDIA, 0)
    assert meta.displayed_size == (600, 800)
    # displayed: left=60 right=240 top=160 bottom=200  ->  y flips: 800-200=600 .. 800-160=640
    _approx(normalized_to_pdf_rect(meta, *FIELD), (60, 600, 240, 640))


def test_rotation_90():
    meta = PageMeta(MEDIA, MEDIA, 90)
    assert meta.displayed_size == (800, 600)
    # displayed 800x600: left=80 right=320 top=120 bottom=150
    # screen-right == user +y, screen-down == user +x
    _approx(normalized_to_pdf_rect(meta, *FIELD), (120, 80, 150, 320))


def test_rotation_180():
    meta = PageMeta(MEDIA, MEDIA, 180)
    assert meta.displayed_size == (600, 800)
    # displayed: left=60 right=240 top=160 bottom=200 -> x mirrored (600-240=360..540), y = 160..200
    _approx(normalized_to_pdf_rect(meta, *FIELD), (360, 160, 540, 200))


def test_rotation_270():
    meta = PageMeta(MEDIA, MEDIA, 270)
    assert meta.displayed_size == (800, 600)
    # displayed 800x600: left=80 right=320 top=120 bottom=150
    # screen-right == user -y, screen-down == user -x
    _approx(normalized_to_pdf_rect(meta, *FIELD), (450, 480, 480, 720))


def test_cropbox_offset():
    meta = PageMeta(MEDIA, (100.0, 50.0, 500.0, 750.0), 0)  # crop 400x700
    assert meta.displayed_size == (400, 700)
    # displayed: left=40 right=160 top=140 bottom=175 -> x: 100+40..100+160, y: 750-175..750-140
    _approx(normalized_to_pdf_rect(meta, *FIELD), (140, 575, 260, 610))


def test_cropbox_offset_rotated_90():
    meta = PageMeta(MEDIA, (100.0, 50.0, 500.0, 750.0), 90)  # displayed 700x400
    assert meta.displayed_size == (700, 400)
    # displayed: left=70 right=280 top=80 bottom=100 -> x: 100+80..100+100, y: 50+70..50+280
    _approx(normalized_to_pdf_rect(meta, *FIELD), (180, 120, 200, 330))


@pytest.mark.parametrize("rotation", [0, 90, 180, 270])
def test_full_page_maps_onto_cropbox(rotation):
    crop = (37.0, 21.0, 560.0, 790.0)
    meta = PageMeta(MEDIA, crop, rotation)
    _approx(normalized_to_pdf_rect(meta, 0, 0, 1, 1), crop)


def test_displayed_corners_rotation_90():
    meta = PageMeta(MEDIA, MEDIA, 90)
    # Top-left on screen is the unrotated bottom-left; top-right on screen is the unrotated top-left.
    assert displayed_to_user(meta, 0, 0) == (0, 0)
    assert displayed_to_user(meta, 800, 0) == (0, 800)
    assert displayed_to_user(meta, 0, 600) == (600, 0)


def test_displayed_corners_rotation_270():
    meta = PageMeta(MEDIA, MEDIA, 270)
    assert displayed_to_user(meta, 0, 0) == (600, 800)
    assert displayed_to_user(meta, 800, 0) == (600, 0)
    assert displayed_to_user(meta, 0, 600) == (0, 800)


@pytest.mark.parametrize(
    "rotation,origin",
    [(0, (60, 600)), (90, (150, 80)), (180, (540, 200)), (270, (450, 720))],
)
def test_field_frame_origin_and_size(rotation, origin):
    meta = PageMeta(MEDIA, MEDIA, rotation)
    frame = field_frame(meta, *FIELD)
    assert frame.rotation == rotation
    disp_w, disp_h = meta.displayed_size
    assert frame.width == pytest.approx(0.30 * disp_w)
    assert frame.height == pytest.approx(0.05 * disp_h)
    assert (frame.origin_x, frame.origin_y) == pytest.approx(origin)
    # The frame origin must be one corner of the PDF rectangle.
    rect = normalized_to_pdf_rect(meta, *FIELD)
    assert frame.origin_x in (pytest.approx(rect[0]), pytest.approx(rect[2]))
    assert frame.origin_y in (pytest.approx(rect[1]), pytest.approx(rect[3]))


def test_with_box_mediabox():
    meta = PageMeta(MEDIA, (100.0, 50.0, 500.0, 750.0), 0)
    assert meta.with_box("mediabox").cropbox == MEDIA
    assert meta.with_box("cropbox") is meta


def test_normalize_rect_inverted():
    assert normalize_rect([600, 800, 0, 0]) == (0, 0, 600, 800)
    with pytest.raises(ValueError):
        normalize_rect([1, 2, 3])


@pytest.mark.parametrize("raw,expected", [(0, 0), (90, 90), (-90, 270), (450, 90), (360, 0), (89, 90), (None, 0), ("270", 270)])
def test_normalize_rotation(raw, expected):
    assert normalize_rotation(raw) == expected
