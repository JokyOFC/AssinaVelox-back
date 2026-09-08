"""Page geometry: normalized top-left coordinates -> PDF user space.

Coordinate convention used by the AssinaVelox front-end (and therefore by
``compose`` plans and ``sign --visible``):

* ``x, y, width, height`` are fractions in ``[0, 1]`` of the page as it is
  DISPLAYED, i.e. the CropBox after applying ``/Rotate``.
* The origin is the TOP-LEFT corner of the displayed page (HTML canvas style).

PDF user space has its origin at the bottom-left of the *unrotated* page and
the CropBox does not necessarily start at ``(0, 0)``, so every field has to be
mapped back through the rotation and the box offset. All functions here are
pure so they can be unit-tested without touching a PDF.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Optional, Sequence, Tuple

Rect = Tuple[float, float, float, float]  # (llx, lly, urx, ury)


def normalize_rect(values: Sequence[float]) -> Rect:
    """Return ``(min_x, min_y, max_x, max_y)`` from any 4-number rectangle.

    pypdf's ``RectangleObject`` does *not* normalise inverted coordinates,
    so we do it here.
    """
    if len(values) != 4:
        raise ValueError("rectangle must have exactly 4 numbers")
    x0, y0, x1, y1 = (float(v) for v in values)
    return (min(x0, x1), min(y0, y1), max(x0, x1), max(y0, y1))


def normalize_rotation(rotation) -> int:
    """Clamp any ``/Rotate`` value (negative, > 360, non-multiple) to 0/90/180/270."""
    try:
        r = int(round(float(rotation)))
    except (TypeError, ValueError):
        r = 0
    r %= 360
    return (int(round(r / 90.0)) * 90) % 360


@dataclass(frozen=True)
class PageMeta:
    """Geometry of a single page (all values in PDF points)."""

    mediabox: Rect
    cropbox: Rect
    rotation: int = 0

    @property
    def crop_width(self) -> float:
        return self.cropbox[2] - self.cropbox[0]

    @property
    def crop_height(self) -> float:
        return self.cropbox[3] - self.cropbox[1]

    @property
    def displayed_size(self) -> Tuple[float, float]:
        """(width, height) of the CropBox as displayed after ``/Rotate``."""
        if self.rotation in (90, 270):
            return (self.crop_height, self.crop_width)
        return (self.crop_width, self.crop_height)

    def with_box(self, box: str) -> "PageMeta":
        """Return a copy whose reference box is ``"cropbox"`` (default) or ``"mediabox"``."""
        if box == "mediabox":
            return PageMeta(self.mediabox, self.mediabox, self.rotation)
        return self


def intersect_rect(a: Rect, b: Rect) -> Optional[Rect]:
    llx, lly = max(a[0], b[0]), max(a[1], b[1])
    urx, ury = min(a[2], b[2]), min(a[3], b[3])
    if urx - llx <= 0 or ury - lly <= 0:
        return None
    return (llx, lly, urx, ury)


def page_meta_from_pypdf(page) -> PageMeta:
    """Build a :class:`PageMeta` from a ``pypdf.PageObject``.

    pypdf resolves inherited ``/MediaBox``, ``/CropBox`` and ``/Rotate`` when
    it flattens the page tree, so the page-level accessors are sufficient.
    A CropBox is clipped to the MediaBox (ISO 32000-1 14.11.2); a degenerate
    or missing CropBox falls back to the MediaBox.
    """
    mediabox = normalize_rect(list(page.mediabox))
    try:
        cropbox = normalize_rect(list(page.cropbox))
    except Exception:  # noqa: BLE001 - malformed box, fall back to MediaBox
        cropbox = mediabox
    clipped = intersect_rect(cropbox, mediabox)
    if clipped is None:
        clipped = mediabox
    try:
        rotation = normalize_rotation(page.rotation)
    except Exception:  # noqa: BLE001
        rotation = 0
    return PageMeta(mediabox=mediabox, cropbox=clipped, rotation=rotation)


def displayed_to_user(meta: PageMeta, dx: float, dy: float) -> Tuple[float, float]:
    """Map a point in displayed points (origin top-left, y down) to user space.

    ``dx`` grows to the right and ``dy`` grows downwards on screen.
    """
    cx0, cy0, cx1, cy1 = meta.cropbox
    r = meta.rotation
    if r == 0:
        # Displayed top-left is the CropBox top-left corner.
        return (cx0 + dx, cy1 - dy)
    if r == 90:
        # Page rotated 90 deg clockwise for display: the unrotated bottom-left
        # corner becomes the displayed top-left. Screen-right == user +y,
        # screen-down == user +x.
        return (cx0 + dy, cy0 + dx)
    if r == 180:
        # Displayed top-left is the unrotated bottom-right corner.
        return (cx1 - dx, cy0 + dy)
    if r == 270:
        # Displayed top-left is the unrotated top-right corner.
        # Screen-right == user -y, screen-down == user -x.
        return (cx1 - dy, cy1 - dx)
    raise ValueError(f"unsupported rotation {r}")


def normalized_to_pdf_rect(meta: PageMeta, x: float, y: float, w: float, h: float) -> Rect:
    """Convert a normalized (top-left origin) box to a PDF user-space rectangle.

    Returns ``(llx, lly, urx, ury)`` in points, normalised so that
    ``llx <= urx`` and ``lly <= ury`` regardless of rotation.
    """
    disp_w, disp_h = meta.displayed_size
    dx0, dy0 = x * disp_w, y * disp_h
    dx1, dy1 = (x + w) * disp_w, (y + h) * disp_h
    corners = [
        displayed_to_user(meta, dx0, dy0),
        displayed_to_user(meta, dx1, dy0),
        displayed_to_user(meta, dx0, dy1),
        displayed_to_user(meta, dx1, dy1),
    ]
    xs = [c[0] for c in corners]
    ys = [c[1] for c in corners]
    return (min(xs), min(ys), max(xs), max(ys))


@dataclass(frozen=True)
class FieldFrame:
    """A local drawing frame for a field.

    Translate the canvas to ``(origin_x, origin_y)`` and rotate by
    ``rotation`` degrees (counter-clockwise, reportlab convention). Inside
    that frame the field occupies ``(0, 0)-(width, height)`` with +x pointing
    to the displayed right and +y pointing to the displayed up, so anything
    drawn upright in the frame appears upright to the viewer.
    """

    origin_x: float
    origin_y: float
    rotation: int
    width: float
    height: float


def field_frame(meta: PageMeta, x: float, y: float, w: float, h: float) -> FieldFrame:
    """Local frame anchored at the displayed bottom-left corner of the field.

    ``/Rotate r`` means the viewer rotates the page ``r`` degrees clockwise.
    Drawing content rotated ``r`` degrees counter-clockwise in user space
    therefore makes it upright on screen.
    """
    disp_w, disp_h = meta.displayed_size
    box_w, box_h = w * disp_w, h * disp_h
    # Displayed bottom-left corner of the field (left edge, bottom edge).
    ox, oy = displayed_to_user(meta, x * disp_w, (y + h) * disp_h)
    return FieldFrame(origin_x=ox, origin_y=oy, rotation=meta.rotation, width=box_w, height=box_h)
