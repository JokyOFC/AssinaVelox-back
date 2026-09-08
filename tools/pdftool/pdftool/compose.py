"""``compose`` (flatten plan fields with a reportlab overlay) and ``append``."""

from __future__ import annotations

import io
import json
import logging
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from PIL import Image, UnidentifiedImageError
from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas as rl_canvas

from pdftool.errors import InputRejected, ProcessingError, UsageError
from pdftool.geometry import PageMeta, field_frame, page_meta_from_pypdf
from pdftool.inspect_cmd import close_reader, open_reader

log = logging.getLogger(__name__)

FIELD_TYPES = {"signature", "initials", "name", "date", "text", "checkbox"}
IMAGE_TYPES = {"signature", "initials"}
TEXT_TYPES = {"name", "date", "text"}
DEFAULT_FONT = "Helvetica"
MIN_FONT_SIZE = 5.0
PADDING_PT = 2.0
MAX_FIELD_IMAGE_SIDE = 2000
_TRUE_STRINGS = {"true", "1", "yes", "on", "sim"}
_FALSE_STRINGS = {"false", "0", "no", "off", "nao", "não", ""}


@dataclass
class Field:
    id: str
    page: int
    type: str
    x: float
    y: float
    w: float
    h: float
    box: str
    value: Any
    image: Optional[Path]
    font_size: float
    align: str


def _invalid(fid: str, msg: str) -> UsageError:
    return UsageError("invalid_plan", f"field {fid}: {msg}")


def _num(raw: Dict[str, Any], key: str, fid: str, default=None) -> float:
    value = raw.get(key, default)
    if value is None:
        raise _invalid(fid, f"{key} is required")
    if isinstance(value, bool):
        raise _invalid(fid, f"{key} must be a number")
    try:
        return float(value)
    except (TypeError, ValueError):
        raise _invalid(fid, f"{key} must be a number") from None


def _to_bool(value, fid: str) -> bool:
    if isinstance(value, bool):
        return value
    if value is None:
        return False
    if isinstance(value, (int, float)):
        return value != 0
    if isinstance(value, str):
        lowered = value.strip().lower()
        if lowered in _TRUE_STRINGS:
            return True
        if lowered in _FALSE_STRINGS:
            return False
    raise _invalid(fid, "checkbox value must be a boolean")


def parse_plan(plan: Any) -> Tuple[Path, Optional[Dict[str, Any]], List[Field]]:
    if not isinstance(plan, dict):
        raise UsageError("invalid_plan", "plan must be a JSON object")
    source = plan.get("source")
    if not isinstance(source, str) or not source.strip():
        raise UsageError("invalid_plan", "plan.source must be a non-empty path")
    font = plan.get("font")
    if font is not None and not isinstance(font, dict):
        raise UsageError("invalid_plan", "plan.font must be an object")
    raw_fields = plan.get("fields", [])
    if not isinstance(raw_fields, list):
        raise UsageError("invalid_plan", "plan.fields must be an array")

    fields: List[Field] = []
    for index, raw in enumerate(raw_fields, start=1):
        if not isinstance(raw, dict):
            raise UsageError("invalid_plan", f"fields[{index}] must be an object")
        fid = str(raw.get("id") or f"field_{index}")
        ftype = str(raw.get("type", "")).strip().lower()
        if ftype not in FIELD_TYPES:
            raise _invalid(fid, f"unknown type {ftype!r}")
        page_raw = raw.get("page")
        if isinstance(page_raw, bool) or not isinstance(page_raw, (int, float)) or int(page_raw) != page_raw or int(page_raw) < 1:
            raise _invalid(fid, "page must be an integer >= 1")
        page = int(page_raw)
        x, y = _num(raw, "x", fid), _num(raw, "y", fid)
        w, h = _num(raw, "width", fid), _num(raw, "height", fid)
        eps = 1e-6
        if not (0 - eps <= x <= 1 + eps and 0 - eps <= y <= 1 + eps):
            raise _invalid(fid, "x and y must be within [0, 1]")
        if w <= 0 or h <= 0:
            raise _invalid(fid, "width and height must be > 0")
        if x + w > 1 + 1e-3 or y + h > 1 + 1e-3:
            raise _invalid(fid, "field extends beyond the page")
        box = str(raw.get("box") or "cropbox").lower()
        if box not in ("cropbox", "mediabox"):
            raise _invalid(fid, "box must be 'cropbox' or 'mediabox'")
        font_size = _num(raw, "font_size", fid, default=10)
        if font_size <= 0:
            raise _invalid(fid, "font_size must be > 0")
        align = str(raw.get("align") or "left").lower()
        if align not in ("left", "center", "right"):
            raise _invalid(fid, "align must be 'left', 'center' or 'right'")

        value = raw.get("value")
        image: Optional[Path] = None
        if ftype in IMAGE_TYPES:
            image_src = raw.get("image") or (value if isinstance(value, str) else None)
            if not isinstance(image_src, str) or not image_src.strip():
                raise _invalid(fid, "signature/initials fields require an 'image' path")
            image = Path(image_src).expanduser()
        elif ftype == "checkbox":
            value = _to_bool(value, fid)
        else:
            value = "" if value is None else str(value)

        fields.append(Field(fid, page, ftype, x, y, w, h, box, value, image, font_size, align))
    return Path(source).expanduser(), font, fields


def register_font(font_spec: Optional[Dict[str, Any]]) -> str:
    """Register the plan's TrueType font, if any, and return the font name to use."""
    if not font_spec or not font_spec.get("path"):
        return DEFAULT_FONT
    name = str(font_spec.get("name") or "Custom")
    path = Path(str(font_spec["path"])).expanduser()
    if not path.is_file():
        raise InputRejected("missing_font", f"font file not found: {path}")
    try:
        pdfmetrics.registerFont(TTFont(name, str(path)))
    except Exception as exc:  # noqa: BLE001
        raise InputRejected("invalid_font", f"cannot load TrueType font: {type(exc).__name__}: {exc}") from exc
    return name


def _is_empty(field: Field) -> bool:
    if field.type in TEXT_TYPES:
        return not str(field.value).strip()
    if field.type == "checkbox":
        return field.value is False
    return False


def _sanitize_text(text: str, font_name: str) -> str:
    text = " ".join(str(text).replace("\r", " ").replace("\n", " ").replace("\t", " ").split(" ")).strip()
    if font_name in pdfmetrics.standardFonts:
        # Standard Type 1 fonts are WinAnsi-encoded (covers Portuguese accents).
        text = text.encode("cp1252", "replace").decode("cp1252")
    return text


def _fit_font_size(text: str, font_name: str, size: float, avail_w: float, avail_h: float) -> float:
    size = max(float(size), MIN_FONT_SIZE)
    while True:
        ascent, descent = pdfmetrics.getAscentDescent(font_name, size)
        fits_w = pdfmetrics.stringWidth(text, font_name, size) <= avail_w
        fits_h = (ascent - descent) <= avail_h
        if (fits_w and fits_h) or size <= MIN_FONT_SIZE:
            return size
        size = max(MIN_FONT_SIZE, size - 0.5)


def _draw_text(c, text: str, box_w: float, box_h: float, font_name: str, font_size: float, align: str) -> None:
    text = _sanitize_text(text, font_name)
    if not text:
        return
    avail_w = max(box_w - 2 * PADDING_PT, 1.0)
    avail_h = max(box_h - 2 * PADDING_PT, 1.0)
    size = _fit_font_size(text, font_name, font_size, avail_w, avail_h)
    text_w = pdfmetrics.stringWidth(text, font_name, size)
    ascent, descent = pdfmetrics.getAscentDescent(font_name, size)
    if align == "center":
        x = max(PADDING_PT, (box_w - text_w) / 2)
    elif align == "right":
        x = max(PADDING_PT, box_w - PADDING_PT - text_w)
    else:
        x = PADDING_PT
    # Centre the glyph box (baseline + descent .. baseline + ascent) vertically.
    baseline = (box_h - ascent - descent) / 2
    c.setFillColorRGB(0, 0, 0)
    c.setFont(font_name, size)
    c.drawString(x, baseline, text)


def _load_field_image(path: Path) -> Image.Image:
    try:
        with Image.open(path) as im:
            im.load()
            has_alpha = im.mode in ("RGBA", "LA") or (im.mode == "P" and "transparency" in im.info)
            pil = im.convert("RGBA") if has_alpha else im.convert("RGB")
    except UnidentifiedImageError as exc:
        raise InputRejected("invalid_image", f"file is not a recognised image: {path.name}") from exc
    except (OSError, ValueError, SyntaxError) as exc:
        raise InputRejected("invalid_image", f"cannot decode image {path.name}: {type(exc).__name__}") from exc
    if pil.width <= 0 or pil.height <= 0:
        raise InputRejected("invalid_image", f"image has an empty dimension: {path.name}")
    if max(pil.size) > MAX_FIELD_IMAGE_SIDE:
        pil.thumbnail((MAX_FIELD_IMAGE_SIDE, MAX_FIELD_IMAGE_SIDE), Image.Resampling.LANCZOS)
    return pil


def _draw_image(c, path: Path, box_w: float, box_h: float) -> None:
    pil = _load_field_image(path)
    iw, ih = pil.size
    scale = min(box_w / iw, box_h / ih)
    dw, dh = iw * scale, ih * scale
    # mask='auto' turns an alpha channel into an /SMask (transparent background).
    c.drawImage(ImageReader(pil), (box_w - dw) / 2, (box_h - dh) / 2, width=dw, height=dh, mask="auto")


def _draw_check(c, box_w: float, box_h: float) -> None:
    side = max(min(box_w, box_h) - 2 * PADDING_PT, 1.0)
    x0, y0 = (box_w - side) / 2, (box_h - side) / 2
    c.setStrokeColorRGB(0, 0, 0)
    c.setLineWidth(max(0.5, side * 0.05))
    c.rect(x0, y0, side, side, stroke=1, fill=0)
    # Check mark: two strokes (short down-stroke, long up-stroke).
    c.setLineWidth(max(0.75, side * 0.09))
    c.setLineCap(1)
    c.setLineJoin(1)
    path = c.beginPath()
    path.moveTo(x0 + 0.20 * side, y0 + 0.52 * side)
    path.lineTo(x0 + 0.42 * side, y0 + 0.24 * side)
    path.lineTo(x0 + 0.82 * side, y0 + 0.78 * side)
    c.drawPath(path, stroke=1, fill=0)


def render_overlay(meta: PageMeta, fields: List[Field], font_name: str) -> bytes:
    """Draw ``fields`` on a blank reportlab page the size of the source MediaBox.

    The overlay's content stream is later concatenated to the source page's
    content stream by pypdf, so both share the same default user space:
    coordinates are absolute (MediaBox origin offsets need no translation).
    Each field gets a local frame that is rotated with ``/Rotate`` so the
    result is upright for the viewer (see :func:`pdftool.geometry.field_frame`).
    """
    media_w = meta.mediabox[2] - meta.mediabox[0]
    media_h = meta.mediabox[3] - meta.mediabox[1]
    buf = io.BytesIO()
    c = rl_canvas.Canvas(buf, pagesize=(media_w, media_h), pageCompression=0)
    for field in fields:
        frame = field_frame(meta.with_box(field.box), field.x, field.y, field.w, field.h)
        c.saveState()
        c.translate(frame.origin_x, frame.origin_y)
        if frame.rotation:
            c.rotate(frame.rotation)
        if field.type in IMAGE_TYPES:
            _draw_image(c, field.image, frame.width, frame.height)
        elif field.type == "checkbox":
            _draw_check(c, frame.width, frame.height)
        else:
            _draw_text(c, field.value, frame.width, frame.height, font_name, field.font_size, field.align)
        c.restoreState()
    c.showPage()
    c.save()
    return buf.getvalue()


def reject_same_path(src: Path, out: Path) -> None:
    try:
        same = src.resolve() == out.resolve()
    except OSError:
        same = False
    if same:
        raise UsageError("same_path", "output path must differ from the input path")


def load_plan_file(plan_path: Path) -> Any:
    if not plan_path.is_file():
        raise UsageError("missing_plan", f"plan file not found: {plan_path}")
    try:
        return json.loads(plan_path.read_text(encoding="utf-8-sig"))
    except (OSError, ValueError) as exc:
        raise UsageError("invalid_plan", f"cannot parse plan JSON: {exc}") from exc


def compose(plan_path: Path, out_path: Path) -> Dict[str, Any]:
    plan = load_plan_file(plan_path)
    source_path, font_spec, fields = parse_plan(plan)
    if not source_path.is_file():
        raise InputRejected("missing_source", f"source PDF not found: {source_path}")
    reject_same_path(source_path, out_path)
    font_name = register_font(font_spec)
    for field in fields:  # fail before touching the output
        if field.type in IMAGE_TYPES and not field.image.is_file():
            raise InputRejected("missing_image", f"field {field.id}: image not found: {field.image}")

    reader, _info = open_reader(source_path)
    try:
        page_count = len(reader.pages)
        for field in fields:
            if field.page > page_count:
                raise _invalid(field.id, f"page {field.page} out of range (document has {page_count} pages)")
        writer = PdfWriter(clone_from=reader)
        by_page: Dict[int, List[Field]] = defaultdict(list)
        skipped: List[str] = []
        for field in fields:
            if _is_empty(field):
                skipped.append(field.id)
            else:
                by_page[field.page].append(field)

        drawn = 0
        for page_no, page_fields in sorted(by_page.items()):
            page = writer.pages[page_no - 1]
            meta = page_meta_from_pypdf(page)
            overlay_page = PdfReader(io.BytesIO(render_overlay(meta, page_fields, font_name))).pages[0]
            # pypdf clips merged content to the overlay's CropBox: make it the
            # source MediaBox so absolute coordinates are never clipped.
            box = RectangleObject(list(meta.mediabox))
            overlay_page.mediabox = box
            overlay_page.cropbox = box
            page.merge_page(overlay_page, expand=False)
            drawn += len(page_fields)

        try:
            out_path.parent.mkdir(parents=True, exist_ok=True)
            with out_path.open("wb") as fh:
                writer.write(fh)
        except OSError as exc:
            raise ProcessingError("pdf_write_failed", f"could not write output: {exc}") from exc
    finally:
        close_reader(reader)
    return {"ok": True, "page_count": page_count, "fields_drawn": drawn, "skipped": skipped}


def append_pdfs(base: Path, extra: Path, out: Path) -> Dict[str, Any]:
    reject_same_path(base, out)
    reject_same_path(extra, out)
    base_reader, _ = open_reader(base)
    try:
        extra_reader, _ = open_reader(extra)
        try:
            writer = PdfWriter(clone_from=base_reader)
            # add_page keeps each page's own /MediaBox, /CropBox and /Rotate
            # (pypdf writes inherited attributes onto the page when flattening).
            for page in extra_reader.pages:
                writer.add_page(page)
            page_count = len(writer.pages)
            try:
                out.parent.mkdir(parents=True, exist_ok=True)
                with out.open("wb") as fh:
                    writer.write(fh)
            except OSError as exc:
                raise ProcessingError("pdf_write_failed", f"could not write output: {exc}") from exc
        finally:
            close_reader(extra_reader)
    finally:
        close_reader(base_reader)
    return {"ok": True, "page_count": page_count}
