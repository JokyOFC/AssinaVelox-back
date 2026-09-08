"""Image normalisation (Pillow) and the ``image2pdf`` command."""

from __future__ import annotations

import io
import warnings
from pathlib import Path
from typing import Any, Dict, Tuple

from PIL import Image, ImageOps, UnidentifiedImageError
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas as rl_canvas

from pdftool.errors import InputRejected, ProcessingError, UsageError
from pdftool.inspect_cmd import inspect_pdf

SUPPORTED_FORMATS = {"PNG", "JPEG", "WEBP"}
MAX_PIXELS = 40_000_000  # 40 MP hard limit
MAX_SIDE_PX = 4000
MAX_PAGE_PT = 14400.0  # Acrobat's implementation limit for a page side
PAGE_SIZES_PT = {"a4": (595.2756, 841.8898), "letter": (612.0, 792.0)}

# Pillow warns above this many pixels and raises DecompressionBombError above
# twice this many. We additionally enforce MAX_PIXELS ourselves right after
# reading the header, before any pixel data is decoded.
Image.MAX_IMAGE_PIXELS = MAX_PIXELS


def _flatten_to_rgb(im: Image.Image) -> Image.Image:
    """Composite transparent images on white and return an RGB image."""
    has_alpha = im.mode in ("RGBA", "LA") or (im.mode == "P" and "transparency" in im.info)
    if has_alpha:
        rgba = im.convert("RGBA")
        background = Image.new("RGBA", rgba.size, (255, 255, 255, 255))
        background.alpha_composite(rgba)
        return background.convert("RGB")
    return im.convert("RGB")


def load_normalized_image(path: Path) -> Tuple[Image.Image, Dict[str, Any]]:
    """Open, validate and normalise an image.

    * only PNG / JPEG / WEBP are accepted;
    * more than :data:`MAX_PIXELS` pixels (or a decompression bomb) is rejected;
    * EXIF orientation is applied, then ALL metadata is dropped by copying the
      pixels into a brand-new image;
    * the result is RGB with the longest side capped at :data:`MAX_SIDE_PX`.
    """
    if not path.is_file():
        raise InputRejected("missing_input", f"image file not found: {path}")
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", Image.DecompressionBombWarning)
            with Image.open(path) as im:
                fmt = im.format or "UNKNOWN"
                if fmt not in SUPPORTED_FORMATS:
                    raise InputRejected(
                        "unsupported_image", f"unsupported image format {fmt}; use PNG, JPEG or WEBP"
                    )
                width, height = im.size
                if width <= 0 or height <= 0:
                    raise InputRejected("invalid_image", "image has an empty dimension")
                if width * height > MAX_PIXELS:
                    raise InputRejected(
                        "image_too_large", f"image has {width * height} pixels; the limit is {MAX_PIXELS}"
                    )
                im.load()
                transposed = ImageOps.exif_transpose(im)
                rgb = _flatten_to_rgb(transposed if transposed is not None else im)
    except InputRejected:
        raise
    except Image.DecompressionBombError as exc:
        raise InputRejected("image_too_large", f"image rejected as decompression bomb: {exc}") from exc
    except UnidentifiedImageError as exc:
        raise InputRejected("invalid_image", "file is not a recognised image") from exc
    except (OSError, ValueError, SyntaxError) as exc:
        raise InputRejected("invalid_image", f"cannot decode image: {type(exc).__name__}: {exc}") from exc

    # Fresh image => no EXIF / ICC / XMP / text chunks survive.
    clean = Image.new("RGB", rgb.size, (255, 255, 255))
    clean.paste(rgb)
    if max(clean.size) > MAX_SIDE_PX:
        clean.thumbnail((MAX_SIDE_PX, MAX_SIDE_PX), Image.Resampling.LANCZOS)
    return clean, {"width_px": width, "height_px": height, "format": fmt}


def image_to_pdf(in_path: Path, out_path: Path, page: str = "a4", margin_pt: float = 36.0) -> Dict[str, Any]:
    page = (page or "a4").lower()
    if page not in PAGE_SIZES_PT and page != "fit":
        raise UsageError("invalid_page_size", f"unknown page size {page!r}; use A4, letter or fit")
    if margin_pt < 0:
        raise UsageError("invalid_margin", "margin must be >= 0")

    img, source = load_normalized_image(in_path)
    iw, ih = img.size

    if page == "fit":
        pw, ph = float(iw), float(ih)
        scale = min(1.0, MAX_PAGE_PT / max(pw, ph))
        pw, ph = pw * scale, ph * scale
        x, y, dw, dh = 0.0, 0.0, pw, ph
    else:
        short, long = PAGE_SIZES_PT[page]
        pw, ph = (long, short) if iw > ih else (short, long)  # landscape for wide images
        avail_w, avail_h = pw - 2 * margin_pt, ph - 2 * margin_pt
        if avail_w <= 0 or avail_h <= 0:
            raise UsageError("invalid_margin", "margin leaves no drawable area on the page")
        s = min(avail_w / iw, avail_h / ih)
        dw, dh = iw * s, ih * s
        x, y = (pw - dw) / 2, (ph - dh) / 2

    buf = io.BytesIO()
    if source["format"] == "JPEG":
        # Re-encoded without EXIF; reportlab embeds JPEG data as-is (DCTDecode).
        img.save(buf, "JPEG", quality=90, optimize=True)
    else:
        img.save(buf, "PNG")
    buf.seek(0)

    try:
        out_path.parent.mkdir(parents=True, exist_ok=True)
        c = rl_canvas.Canvas(str(out_path), pagesize=(pw, ph))
        c.setTitle("")
        c.setAuthor("")
        c.setSubject("")
        c.drawImage(ImageReader(buf), x, y, width=dw, height=dh)
        c.showPage()
        c.save()
    except Exception as exc:  # noqa: BLE001
        raise ProcessingError("pdf_write_failed", f"could not write PDF: {type(exc).__name__}: {exc}") from exc

    result = inspect_pdf(out_path)
    result["source"] = source
    result["normalized"] = {"width_px": iw, "height_px": ih}
    result["page_size"] = page
    return result
