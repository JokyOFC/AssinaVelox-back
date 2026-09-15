"""``find-anchors``: locate anchor text in a PDF and return NORMALIZED boxes (roadmap 3.2).

What it looks for
-----------------
* **Markers** with a closed grammar: ``{{assinatura:papel}}``, ``{{rubrica:papel}}``,
  ``{{data:papel}}`` and ``{{texto:nome}}`` (spaces, accents and letter case are ignored).
* **Literal phrases** listed in a spec file (``{"literals": [{"id": "r1", "text": "..."}]}``).
  The search is a plain substring search over normalized text (Unicode NFKD without
  combining marks, case-folded, whitespace collapsed) with word boundaries. **No regular
  expression supplied by the caller is ever compiled**: the only pattern is the fixed marker
  grammar below.

Coordinates
-----------
Every box is ``{"x", "y", "width", "height"}`` in fractions of the page AS DISPLAYED: the
CropBox after ``/Rotate``, origin at the top-left corner, ``y`` growing downwards. This is
exactly the convention of ``compose`` / ``sign --visible`` (``pdftool/geometry.py``),
``App\\Services\\Envelopes\\FieldGeometry`` and ``docs/arquitetura.md`` 3.1.

pdfplumber (pdfminer) reports glyph boxes in a "rotated MediaBox" space. We never trust
that space directly: each corner is mapped back to PDF user space with
``geometry.displayed_to_user`` on the MediaBox and then forward to the displayed CropBox with
:func:`user_to_displayed`, the exact inverse of ``displayed_to_user``. Glyphs outside the
CropBox are dropped.

Scanned pages (OCR, optional)
-----------------------------
Pages with fewer than ``min_text_chars`` glyphs can be OCR-ed when ``--ocr`` is given:
pypdfium2 renders the displayed CropBox (rotation applied) to a PNG in the private
temporary directory and the ``tesseract`` binary runs as a separate process (argument list,
no shell, timeout, minimal environment, no network). Its TSV word boxes are already in
displayed coordinates. Per-page OCR failures never fail the command; they are reported.

Untrusted data (roadmap T6)
---------------------------
Text extracted from the PDF or from OCR is only COMPARED. It is never evaluated and never
echoed back: a match carries the marker type, a slug of the marker key (``[a-z0-9_-]``,
at most 40 characters) or the caller's literal id — never the surrounding text.
"""

from __future__ import annotations

import json
import math
import os
import re
import shutil
import subprocess
import tempfile
import time
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable, Dict, Iterable, List, Optional, Sequence, Tuple

from pdftool.errors import InputRejected, ProcessingError, UsageError
from pdftool.geometry import PageMeta, page_meta_from_pypdf
from pdftool.inspect_cmd import close_reader, open_reader

Box = Tuple[float, float, float, float]  # (x0, y0, x1, y1) normalized, top-left origin

MARKER_FIELD_TYPES = {
    "assinatura": "signature",
    "rubrica": "initials",
    "data": "date",
    "texto": "text",
}

# Fixed grammar, applied to NORMALIZED text (lowercase, no accents, single spaces).
_MARKER_RE = re.compile(
    r"\{\{ ?(assinatura|rubrica|data|texto) ?: ?([a-z0-9](?:[a-z0-9 _.\-]{0,38}[a-z0-9])?) ?\}\}"
)

# A barrier never matches anything: patterns are normalized without control characters.
_BARRIER = "\x00"

DEFAULT_MAX_PAGES = 200
HARD_MAX_PAGES = 1000
DEFAULT_MAX_BYTES = 50 * 1024 * 1024
DEFAULT_TIME_BUDGET = 45.0
DEFAULT_MAX_MATCHES = 500
HARD_MAX_MATCHES = 2000
MAX_LITERALS = 50
MAX_LITERAL_CHARS = 120
DEFAULT_MIN_TEXT_CHARS = 8
# Hostile pages (tens of thousands of tiny glyphs): refused instead of grouped; the clock is
# checked every GLYPH_CLOCK_EVERY glyphs inside a page (adversarial review, wave F).
MAX_GLYPHS_PER_PAGE = 60_000
GLYPH_CLOCK_EVERY = 512

OCR_DEFAULT_DPI = 200
OCR_MIN_DPI = 72
OCR_MAX_DPI = 400
OCR_MAX_PIXELS = 30_000_000
OCR_DEFAULT_TIMEOUT = 60.0
OCR_DEFAULT_MAX_PAGES = 20
OCR_MIN_CONFIDENCE = 0.0

_LITERAL_ID_RE = re.compile(r"^[A-Za-z0-9_\-]{1,40}$")
_LANG_RE = re.compile(r"^[a-z_]{3,20}(\+[a-z_]{3,20}){0,3}$")


# -- Geometry ---------------------------------------------------------------------------


def user_to_displayed(meta: PageMeta, ux: float, uy: float) -> Tuple[float, float]:
    """Inverse of :func:`pdftool.geometry.displayed_to_user` (displayed points, top-left)."""
    cx0, cy0, cx1, cy1 = meta.cropbox
    r = meta.rotation
    if r == 0:
        return (ux - cx0, cy1 - uy)
    if r == 90:
        return (uy - cy0, ux - cx0)
    if r == 180:
        return (cx1 - ux, uy - cy0)
    if r == 270:
        return (cy1 - uy, cx1 - ux)
    raise ValueError(f"unsupported rotation {r}")


def pdfminer_to_user(mediabox: Sequence[float], rotate: int, X: float, Y: float) -> Tuple[float, float]:
    """Undo the page CTM of ``pdfminer.pdfinterp.PDFPageInterpreter.process_page``.

    pdfminer lays a page out in a y-up space already rotated by ``/Rotate`` and shifted by
    the MediaBox origin. This is the exact inverse of that CTM, using the same MediaBox and
    rotation pdfminer used, so the result is plain PDF user space.
    """
    x0, y0, x1, y1 = (float(v) for v in mediabox)
    r = int(rotate or 0) % 360
    if r == 90:
        return (x1 - Y, X + y0)
    if r == 180:
        return (x1 - X, y1 - Y)
    if r == 270:
        return (Y + x0, y1 - X)
    return (X + x0, Y + y0)


def user_rect_to_normalized(meta: PageMeta, corners: Iterable[Tuple[float, float]]) -> Box:
    """User-space points -> normalized box on the displayed CropBox (may exceed [0, 1])."""
    disp_w, disp_h = meta.displayed_size
    xs: List[float] = []
    ys: List[float] = []
    for ux, uy in corners:
        cx, cy = user_to_displayed(meta, ux, uy)
        xs.append(cx / disp_w if disp_w > 0 else 0.0)
        ys.append(cy / disp_h if disp_h > 0 else 0.0)
    return (min(xs), min(ys), max(xs), max(ys))


def plumber_char_to_normalized(ch: Dict[str, Any], page, meta: PageMeta) -> Box:
    """One pdfplumber char -> normalized displayed CropBox box.

    ``y0``/``y1`` are pdfminer's raw layout values. pdfplumber shifts ``x0``/``x1`` by its own
    ``mediabox[0]`` (pdfplumber issue #1181), so that shift is removed before undoing the CTM.
    """
    shift = float(page.mediabox[0]) if getattr(page, "mediabox", None) else 0.0
    X0, X1 = float(ch["x0"]) - shift, float(ch["x1"]) - shift
    Y0, Y1 = float(ch["y0"]), float(ch["y1"])
    page_obj = page.page_obj
    mediabox = page_obj.mediabox
    rotate = getattr(page_obj, "rotate", 0)
    corners = [pdfminer_to_user(mediabox, rotate, X, Y) for X in (X0, X1) for Y in (Y0, Y1)]
    return user_rect_to_normalized(meta, corners)


def _union(boxes: Iterable[Box]) -> Optional[Box]:
    boxes = list(boxes)
    if not boxes:
        return None
    return (
        min(b[0] for b in boxes),
        min(b[1] for b in boxes),
        max(b[2] for b in boxes),
        max(b[3] for b in boxes),
    )


def _clip(box: Box) -> Optional[Box]:
    """Clip to the page. ``None`` when the box's centre is outside the displayed CropBox."""
    cx = (box[0] + box[2]) / 2.0
    cy = (box[1] + box[3]) / 2.0
    if not (0.0 <= cx <= 1.0 and 0.0 <= cy <= 1.0):
        return None
    x0, y0 = max(0.0, box[0]), max(0.0, box[1])
    x1, y1 = min(1.0, box[2]), min(1.0, box[3])
    if x1 <= x0 or y1 <= y0:
        return None
    return (x0, y0, x1, y1)


def _box_json(box: Box) -> Dict[str, float]:
    return {
        "x": round(box[0], 6),
        "y": round(box[1], 6),
        "width": round(box[2] - box[0], 6),
        "height": round(box[3] - box[1], 6),
    }


# -- Normalization ----------------------------------------------------------------------


def normalize_char(ch: str) -> str:
    """One source character -> zero or more normalized characters (``' '`` for whitespace)."""
    if ch.isspace():
        return " "
    decomposed = unicodedata.normalize("NFKD", ch)
    out = []
    for c in decomposed:
        if unicodedata.combining(c):
            continue
        if unicodedata.category(c).startswith("C"):
            continue
        out.append(c)
    return "".join(out).casefold()


def normalize_text(text: str) -> str:
    """Normalization used for literal patterns: same per-character rule + collapsed spaces."""
    chars = "".join(normalize_char(c) for c in text)
    return " ".join(chars.split())


def marker_key_slug(raw: str) -> str:
    slug = re.sub(r"[ .]+", "_", raw.strip())
    slug = re.sub(r"[^a-z0-9_\-]", "", slug)
    return slug[:40]


class _Stream:
    """Normalized text of one page, with the unit (glyph or word) behind each character."""

    def __init__(self) -> None:
        self.chars: List[str] = []
        self.owner: List[Optional[int]] = []

    def add(self, text: str, unit: int) -> None:
        for ch in text:
            for c in normalize_char(ch):
                if c == " ":
                    self.space()
                else:
                    self.chars.append(c)
                    self.owner.append(unit)

    def space(self) -> None:
        if self.chars and self.chars[-1] not in (" ", _BARRIER):
            self.chars.append(" ")
            self.owner.append(None)

    def barrier(self) -> None:
        if not self.chars:
            return
        if self.chars[-1] == " ":
            self.chars[-1] = _BARRIER
        elif self.chars[-1] != _BARRIER:
            self.chars.append(_BARRIER)
            self.owner.append(None)

    def text(self) -> str:
        return "".join(self.chars)


# -- Spec -------------------------------------------------------------------------------


@dataclass(frozen=True)
class Literal:
    id: str
    text: str  # normalized


@dataclass
class Spec:
    markers: bool = True
    literals: List[Literal] = field(default_factory=list)
    max_matches: int = DEFAULT_MAX_MATCHES


def load_spec(path: Optional[Path]) -> Spec:
    if path is None:
        return Spec()
    if not path.is_file():
        raise UsageError("missing_spec", "spec file not found")
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise UsageError("invalid_spec", f"spec is not valid JSON: {type(exc).__name__}") from exc
    return parse_spec(raw)


def parse_spec(raw: Any) -> Spec:
    if not isinstance(raw, dict):
        raise UsageError("invalid_spec", "spec must be a JSON object")
    markers = raw.get("markers", True)
    if not isinstance(markers, bool):
        raise UsageError("invalid_spec", "'markers' must be a boolean")
    max_matches = raw.get("max_matches", DEFAULT_MAX_MATCHES)
    if not isinstance(max_matches, int) or isinstance(max_matches, bool) or not 1 <= max_matches <= HARD_MAX_MATCHES:
        raise UsageError("invalid_spec", f"'max_matches' must be an integer between 1 and {HARD_MAX_MATCHES}")
    literals_raw = raw.get("literals", [])
    if not isinstance(literals_raw, list):
        raise UsageError("invalid_spec", "'literals' must be a list")
    if len(literals_raw) > MAX_LITERALS:
        raise UsageError("invalid_spec", f"at most {MAX_LITERALS} literals are accepted")
    literals: List[Literal] = []
    seen = set()
    for index, item in enumerate(literals_raw):
        if not isinstance(item, dict):
            raise UsageError("invalid_spec", f"literal #{index + 1} must be an object")
        lid = item.get("id")
        text = item.get("text")
        if not isinstance(lid, str) or not _LITERAL_ID_RE.match(lid):
            raise UsageError("invalid_spec", f"literal #{index + 1}: invalid id")
        if lid in seen:
            raise UsageError("invalid_spec", f"literal #{index + 1}: duplicated id")
        if not isinstance(text, str) or len(text) > MAX_LITERAL_CHARS * 4:
            raise UsageError("invalid_spec", f"literal #{index + 1}: invalid text")
        normalized = normalize_text(text)
        if len(normalized) < 2 or len(normalized) > MAX_LITERAL_CHARS:
            raise UsageError("invalid_spec", f"literal #{index + 1}: text must have 2 to {MAX_LITERAL_CHARS} characters")
        seen.add(lid)
        literals.append(Literal(lid, normalized))
    if not markers and not literals:
        raise UsageError("invalid_spec", "nothing to search: enable markers or give literals")
    return Spec(markers=markers, literals=literals, max_matches=max_matches)


# -- Units and matching -----------------------------------------------------------------


@dataclass
class _Unit:
    box: Box
    line: int
    conf: Optional[float] = None


def _boundary_ok(text: str, start: int, end: int) -> bool:
    if start > 0 and text[start].isalnum() and text[start - 1].isalnum():
        return False
    if end < len(text) and text[end - 1].isalnum() and text[end].isalnum():
        return False
    return True


def _match_json(units: List[_Unit], owners: Sequence[Optional[int]], page: int, source: str) -> Optional[Dict[str, Any]]:
    indexes = sorted({o for o in owners if o is not None})
    if not indexes:
        return None
    box = _union(units[i].box for i in indexes)
    first_line = units[indexes[0]].line
    line_box = _union(units[i].box for i in indexes if units[i].line == first_line)
    if box is None or line_box is None:
        return None
    box = _clip(box)
    line_box = _clip(line_box)
    if box is None or line_box is None:
        return None
    confidences = [units[i].conf for i in indexes if units[i].conf is not None]
    return {
        "page": page,
        "source": source,
        "box": _box_json(box),
        "line_box": _box_json(line_box),
        "lines": len({units[i].line for i in indexes}),
        "confidence": round(min(confidences), 2) if confidences else None,
    }


def find_in_stream(stream: _Stream, units: List[_Unit], spec: Spec, page: int, source: str, budget: int) -> List[Dict[str, Any]]:
    """Markers and literals in one page's normalized text. At most ``budget`` matches."""
    text = stream.text()
    found: List[Dict[str, Any]] = []
    if budget <= 0 or not text:
        return found

    if spec.markers:
        for m in _MARKER_RE.finditer(text):
            if len(found) >= budget:
                return found
            match = _match_json(units, stream.owner[m.start():m.end()], page, source)
            if match is None:
                continue
            key = marker_key_slug(m.group(2))
            if not key:
                continue
            match.update({"kind": "marker", "field_type": MARKER_FIELD_TYPES[m.group(1)], "key": key, "literal_id": None})
            found.append(match)

    for literal in spec.literals:
        start = 0
        while len(found) < budget:
            i = text.find(literal.text, start)
            if i < 0:
                break
            j = i + len(literal.text)
            start = i + 1
            if not _boundary_ok(text, i, j):
                continue
            match = _match_json(units, stream.owner[i:j], page, source)
            if match is None:
                continue
            match.update({"kind": "literal", "field_type": None, "key": None, "literal_id": literal.id})
            found.append(match)
            start = j
    return found


# -- Text layer (pdfplumber) ------------------------------------------------------------

_DIRECTIONS = {0: (1.0, 0.0), 90: (0.0, 1.0), 180: (-1.0, 0.0), 270: (0.0, -1.0)}


@dataclass
class _Glyph:
    text: str
    box: Box  # normalized (displayed CropBox)
    direction: int
    along0: float
    along1: float
    across: float
    size: float


def _glyphs(page, meta: PageMeta) -> List[_Glyph]:
    glyphs: List[_Glyph] = []
    for ch in page.chars:
        text = ch.get("text") or ""
        if text == "":
            continue
        try:
            x0, x1 = float(ch["x0"]), float(ch["x1"])
            top, bottom = float(ch["top"]), float(ch["bottom"])
        except (KeyError, TypeError, ValueError):
            continue
        matrix = ch.get("matrix") or (1.0, 0.0, 0.0, 1.0, 0.0, 0.0)
        a, b = float(matrix[0]), float(matrix[1])
        # pdfminer matrices are y-up; the plumber box is y-down.
        angle = math.degrees(math.atan2(-b, a)) if (a or b) else 0.0
        direction = int(round(angle / 90.0)) * 90 % 360
        ux, uy = _DIRECTIONS[direction]
        nx, ny = -uy, ux
        corners = ((x0, top), (x1, top), (x0, bottom), (x1, bottom))
        along = [px * ux + py * uy for px, py in corners]
        across = [px * nx + py * ny for px, py in corners]
        size = float(ch.get("size") or 0.0) or max(abs(bottom - top), abs(x1 - x0), 1.0)
        try:
            box = plumber_char_to_normalized(ch, page, meta)
        except (KeyError, TypeError, ValueError):
            continue
        glyphs.append(
            _Glyph(
                text=text,
                box=box,
                direction=direction,
                along0=min(along),
                along1=max(along),
                across=(min(across) + max(across)) / 2.0,
                size=size,
            )
        )
    return glyphs


def _inside(box: Box) -> bool:
    cx = (box[0] + box[2]) / 2.0
    cy = (box[1] + box[3]) / 2.0
    return 0.0 <= cx <= 1.0 and 0.0 <= cy <= 1.0


def text_units(page, meta: PageMeta, check: Optional[Callable[[], None]] = None) -> Tuple[_Stream, List[_Unit], int]:
    """Reading-order stream of one page. Returns (stream, units, visible glyph count).

    ``check`` (optional) is called every ``GLYPH_CLOCK_EVERY`` glyphs and raises to stop the
    search (the whole-search time budget holds inside a dense page too). Line grouping keeps a
    running sum per line, so it is O(n log n) in the glyph count (the sort), never O(n^2).
    """
    glyphs = [g for g in _glyphs(page, meta) if _inside(g.box)]
    if len(glyphs) > MAX_GLYPHS_PER_PAGE:
        raise InputRejected("too_many_glyphs", f"page has more than {MAX_GLYPHS_PER_PAGE} glyphs")
    visible = sum(1 for g in glyphs if not g.text.isspace())
    stream = _Stream()
    units: List[_Unit] = []
    tick = 0

    def _tick() -> None:
        nonlocal tick
        tick += 1
        if check is not None and tick % GLYPH_CLOCK_EVERY == 0:
            check()

    by_direction: Dict[int, List[_Glyph]] = {}
    for g in glyphs:
        by_direction.setdefault(g.direction, []).append(g)

    line_no = 0
    # Dominant direction first; a page rarely mixes directions.
    for direction in sorted(by_direction, key=lambda d: -len(by_direction[d])):
        items = sorted(by_direction[direction], key=lambda g: g.across)
        lines: List[List[_Glyph]] = []
        line_sum = 0.0  # running sum of ``across`` for lines[-1]
        for g in items:
            _tick()
            if lines:
                current = lines[-1]
                reference = line_sum / len(current)
                tolerance = max(1.0, 0.45 * max(g.size, current[0].size))
                if abs(g.across - reference) <= tolerance:
                    current.append(g)
                    line_sum += g.across
                    continue
            lines.append([g])
            line_sum = g.across

        for line in lines:
            line.sort(key=lambda g: g.along0)
            previous: Optional[_Glyph] = None
            for g in line:
                _tick()
                if previous is not None:
                    gap = g.along0 - previous.along1
                    if gap > 3.0 * g.size:
                        stream.barrier()  # column gap: never match across it
                    elif gap > 0.2 * g.size:
                        stream.space()
                if g.text.isspace():
                    stream.space()
                else:
                    units.append(_Unit(box=g.box, line=line_no))
                    stream.add(g.text, len(units) - 1)
                previous = g
            stream.space()  # line break = one space (markers split across two lines match)
            line_no += 1
        stream.barrier()
    return stream, units, visible


# -- OCR (tesseract, optional) ----------------------------------------------------------


class OcrPageError(Exception):
    """OCR failed for ONE page; reported, never fatal."""

    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


OcrRunner = Callable[[str, Path, str, float], str]


def _ocr_environment(workdir: Path) -> Dict[str, str]:
    env: Dict[str, str] = {}
    for name in ("SYSTEMROOT", "PATH", "TESSDATA_PREFIX", "LD_LIBRARY_PATH"):
        value = os.environ.get(name)
        if value:
            env[name] = value
    env["OMP_THREAD_LIMIT"] = "1"
    env["TEMP"] = env["TMP"] = env["TMPDIR"] = str(workdir)
    env["HOME"] = str(workdir)
    return env


_ACTIVE_CHILDREN: "set[subprocess.Popen]" = set()


def _terminate_children(signum, frame) -> None:  # noqa: ARG001 - signal handler signature
    """SIGTERM from the caller (PHP process timeout): take the running tesseract down too."""
    for proc in list(_ACTIVE_CHILDREN):
        try:
            proc.kill()
        except Exception:  # noqa: BLE001
            pass
    raise SystemExit(128 + int(signum))


def _install_term_handler() -> None:
    if os.name != "posix":
        return
    import signal
    import threading

    if threading.current_thread() is not threading.main_thread():
        return
    try:
        signal.signal(signal.SIGTERM, _terminate_children)
    except (ValueError, OSError):
        pass


def run_tesseract(binary: str, image: Path, lang: str, timeout: float) -> str:
    """Run the tesseract CLI on one image; TSV on stdout. Argument list, no shell.

    The child runs in its own session (POSIX) and is killed on timeout or when this process
    receives SIGTERM (``_install_term_handler``): it never outlives the search.
    """
    argv = [binary, str(image), "stdout", "-l", lang, "--psm", "3", "tsv"]
    try:
        proc = subprocess.Popen(
            argv,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=_ocr_environment(image.parent),
            cwd=str(image.parent),
            shell=False,
            start_new_session=os.name == "posix",
        )
    except OSError as exc:
        raise OcrPageError("exec_failed") from exc
    _ACTIVE_CHILDREN.add(proc)
    try:
        try:
            stdout, _ = proc.communicate(timeout=timeout)
        except subprocess.TimeoutExpired as exc:
            proc.kill()
            proc.communicate()
            raise OcrPageError("timeout") from exc
    finally:
        _ACTIVE_CHILDREN.discard(proc)
    if proc.returncode != 0:
        raise OcrPageError("exit_code")
    return stdout.decode("utf-8", errors="replace")


def parse_tsv(tsv: str, image_width: int, image_height: int) -> List[Tuple[str, Box, Tuple[int, int, int], float]]:
    """Tesseract TSV -> words ``(text, normalized box, (block, par, line), confidence)``."""
    words = []
    if image_width <= 0 or image_height <= 0:
        return words
    lines = tsv.splitlines()
    if not lines:
        return words
    header = lines[0].split("\t")
    try:
        col = {name: header.index(name) for name in ("level", "block_num", "par_num", "line_num", "left", "top", "width", "height", "conf", "text")}
    except ValueError:
        return words
    for row in lines[1:]:
        cells = row.split("\t")
        if len(cells) < len(header):
            continue
        try:
            if int(cells[col["level"]]) != 5:
                continue
            conf = float(cells[col["conf"]])
            left, top = int(cells[col["left"]]), int(cells[col["top"]])
            width, height = int(cells[col["width"]]), int(cells[col["height"]])
            key = (int(cells[col["block_num"]]), int(cells[col["par_num"]]), int(cells[col["line_num"]]))
        except (ValueError, IndexError):
            continue
        text = cells[col["text"]].strip()
        if not text or conf < OCR_MIN_CONFIDENCE or width <= 0 or height <= 0:
            continue
        box = (left / image_width, top / image_height, (left + width) / image_width, (top + height) / image_height)
        words.append((text, box, key, conf))
    return words


def ocr_units(words: List[Tuple[str, Box, Tuple[int, int, int], float]]) -> Tuple[_Stream, List[_Unit]]:
    stream = _Stream()
    units: List[_Unit] = []
    line_ids: Dict[Tuple[int, int, int], int] = {}
    previous_key = None
    for text, box, key, conf in words:
        if previous_key is not None and key[0] != previous_key[0]:
            stream.barrier()  # another block (column): no match across it
        line = line_ids.setdefault(key, len(line_ids))
        units.append(_Unit(box=box, line=line, conf=conf))
        stream.add(text, len(units) - 1)
        stream.space()
        previous_key = key
    return stream, units


def _render_page(pdf, index: int, dpi: int, workdir: Path) -> Tuple[Path, int, int]:
    page = pdf[index]
    try:
        width_pt, height_pt = page.get_size()
        scale = dpi / 72.0
        if width_pt * height_pt * scale * scale > OCR_MAX_PIXELS:
            scale = math.sqrt(OCR_MAX_PIXELS / max(1.0, width_pt * height_pt))
        bitmap = page.render(scale=scale, grayscale=True)
        image = bitmap.to_pil()
        target = workdir / f"page-{index + 1}.png"
        image.save(target, "PNG")
        return target, image.width, image.height
    finally:
        page.close()


# -- Command ----------------------------------------------------------------------------


@dataclass
class OcrOptions:
    tesseract: str
    lang: str = "por"
    dpi: int = OCR_DEFAULT_DPI
    timeout: float = OCR_DEFAULT_TIMEOUT
    max_pages: int = OCR_DEFAULT_MAX_PAGES
    pages: Optional[List[int]] = None
    runner: Optional[OcrRunner] = None


def _validate_limits(max_pages: int, max_bytes: int, time_budget: float, min_text_chars: int) -> None:
    if not 1 <= max_pages <= HARD_MAX_PAGES:
        raise UsageError("usage_error", f"--max-pages must be between 1 and {HARD_MAX_PAGES}")
    if max_bytes < 1024:
        raise UsageError("usage_error", "--max-bytes is too small")
    if not 1.0 <= time_budget <= 600.0:
        raise UsageError("usage_error", "--time-budget must be between 1 and 600 seconds")
    if not 0 <= min_text_chars <= 10_000:
        raise UsageError("usage_error", "--min-text-chars must be between 0 and 10000")


def _validate_ocr(ocr: OcrOptions) -> None:
    if ocr.runner is None and not Path(ocr.tesseract).is_file():
        raise UsageError("ocr_unavailable", "tesseract binary not found")
    if not _LANG_RE.match(ocr.lang):
        raise UsageError("usage_error", "invalid OCR language code")
    if not OCR_MIN_DPI <= ocr.dpi <= OCR_MAX_DPI:
        raise UsageError("usage_error", f"--ocr-dpi must be between {OCR_MIN_DPI} and {OCR_MAX_DPI}")
    if not 1.0 <= ocr.timeout <= 600.0:
        raise UsageError("usage_error", "--ocr-timeout must be between 1 and 600 seconds")
    if not 0 <= ocr.max_pages <= HARD_MAX_PAGES:
        raise UsageError("usage_error", "--ocr-max-pages is out of range")


def find_anchors(
    input_path: Path,
    spec: Spec,
    *,
    max_pages: int = DEFAULT_MAX_PAGES,
    max_bytes: int = DEFAULT_MAX_BYTES,
    time_budget: float = DEFAULT_TIME_BUDGET,
    min_text_chars: int = DEFAULT_MIN_TEXT_CHARS,
    ocr: Optional[OcrOptions] = None,
    clock: Callable[[], float] = time.monotonic,
) -> Dict[str, Any]:
    _validate_limits(max_pages, max_bytes, time_budget, min_text_chars)
    if ocr is not None:
        _validate_ocr(ocr)

    if not input_path.is_file():
        raise InputRejected("missing_input", "input file not found")
    size = input_path.stat().st_size
    if size > max_bytes:
        raise InputRejected("pdf_too_large", f"PDF has {size} bytes; the limit is {max_bytes}")

    started = clock()
    reader, info = open_reader(input_path)
    try:
        if info.get("encrypted"):
            raise InputRejected("encrypted_pdf", "encrypted PDFs are not searched for anchors")
        page_count = len(reader.pages)
        if page_count > max_pages:
            raise InputRejected("too_many_pages", f"PDF has {page_count} pages; the limit is {max_pages}")
        metas = [page_meta_from_pypdf(page) for page in reader.pages]
    finally:
        close_reader(reader)

    import pdfplumber  # heavy import, only for this command

    pages_json: List[Dict[str, Any]] = []
    matches: List[Dict[str, Any]] = []
    truncated = False

    try:
        pdf = pdfplumber.open(str(input_path))
    except Exception as exc:  # noqa: BLE001 - pdfminer raises many types
        raise InputRejected("invalid_pdf", f"cannot parse PDF: {type(exc).__name__}") from exc
    def _check_budget() -> None:
        if clock() - started > time_budget:
            raise ProcessingError("time_budget_exceeded", f"text search exceeded {time_budget:g}s")

    try:
        if len(pdf.pages) != page_count:
            raise InputRejected("invalid_pdf", "inconsistent page tree")
        for index, page in enumerate(pdf.pages, start=1):
            _check_budget()
            meta = metas[index - 1]
            try:
                stream, units, visible = text_units(page, meta, _check_budget)
            except (InputRejected, ProcessingError):
                raise
            except Exception as exc:  # noqa: BLE001 - a broken content stream on one page
                raise InputRejected("invalid_pdf", f"cannot read page {index}: {type(exc).__name__}") from exc
            finally:
                try:
                    page.close()
                except Exception:  # noqa: BLE001
                    pass
            found = find_in_stream(stream, units, spec, index, "text", spec.max_matches - len(matches))
            matches.extend(found)
            # "Limit reached": there MAY be more matches than the ones returned.
            truncated = truncated or len(matches) >= spec.max_matches
            disp_w, disp_h = meta.displayed_size
            pages_json.append(
                {
                    "index": index,
                    "rotation": meta.rotation,
                    "width_pt": round(disp_w, 4),
                    "height_pt": round(disp_h, 4),
                    "text_chars": visible,
                    "has_text": visible >= min_text_chars,
                    "ocr": "not_requested",
                }
            )
    finally:
        try:
            pdf.close()
        except Exception:  # noqa: BLE001
            pass

    without_text = [p["index"] for p in pages_json if not p["has_text"]]
    for p in pages_json:
        if p["has_text"]:
            p["ocr"] = "not_needed"

    ocr_summary: Optional[Dict[str, Any]] = None
    if ocr is not None and without_text:
        ocr_summary = _run_ocr(input_path, ocr, pages_json, without_text, spec, matches, started, time_budget, clock)
        truncated = truncated or ocr_summary.pop("_truncated_matches", False)

    matches.sort(key=lambda m: (m["page"], m["box"]["y"], m["box"]["x"]))
    return {
        "ok": True,
        "page_count": page_count,
        "pages": pages_json,
        "pages_without_text": without_text,
        "matches": matches,
        "match_count": len(matches),
        "truncated": truncated,
        "ocr": ocr_summary,
        "elapsed_ms": int(round((clock() - started) * 1000)),
    }


def _run_ocr(
    input_path: Path,
    ocr: OcrOptions,
    pages_json: List[Dict[str, Any]],
    without_text: List[int],
    spec: Spec,
    matches: List[Dict[str, Any]],
    started: float,
    time_budget: float,
    clock: Callable[[], float],
) -> Dict[str, Any]:
    import pypdfium2

    requested = set(ocr.pages) if ocr.pages else None
    candidates = [n for n in without_text if requested is None or n in requested]
    selected = candidates[: ocr.max_pages]
    runner = ocr.runner or run_tesseract
    by_index = {p["index"]: p for p in pages_json}
    for n in without_text:
        by_index[n]["ocr"] = "skipped"

    summary = {"engine": "tesseract", "lang": ocr.lang, "dpi": ocr.dpi, "pages_done": 0, "pages_failed": 0, "pages_skipped": 0}
    truncated_matches = False
    if ocr.runner is None:
        _install_term_handler()
    workdir = Path(tempfile.mkdtemp(prefix="ocr-"))
    try:
        pdf = pypdfium2.PdfDocument(str(input_path))
        try:
            for n in selected:
                entry = by_index[n]
                # The page's OCR never runs past the whole-search budget: the tesseract timeout is
                # the smaller of the per-page timeout and what is left of the budget.
                remaining = time_budget - (clock() - started)
                if remaining < 1.0:
                    entry["ocr"] = "skipped"
                    entry["ocr_error"] = "time_budget"
                    continue
                try:
                    image, width, height = _render_page(pdf, n - 1, ocr.dpi, workdir)
                except Exception:  # noqa: BLE001 - rendering a hostile page
                    entry["ocr"] = "failed"
                    entry["ocr_error"] = "render_failed"
                    continue
                try:
                    tsv = runner(ocr.tesseract, image, ocr.lang, max(1.0, min(ocr.timeout, remaining)))
                except OcrPageError as exc:
                    entry["ocr"] = "failed"
                    entry["ocr_error"] = exc.code
                    continue
                finally:
                    try:
                        image.unlink()
                    except OSError:
                        pass
                words = parse_tsv(tsv, width, height)
                stream, units = ocr_units(words)
                budget = spec.max_matches - len(matches)
                found = find_in_stream(stream, units, spec, n, "ocr", budget)
                if budget <= 0 or len(found) >= budget:
                    truncated_matches = True
                matches.extend(found)
                entry["ocr"] = "done"
                entry["ocr_words"] = len(words)
        finally:
            pdf.close()
    finally:
        shutil.rmtree(workdir, ignore_errors=True)

    for n in without_text:
        state = by_index[n]["ocr"]
        if state == "done":
            summary["pages_done"] += 1
        elif state == "failed":
            summary["pages_failed"] += 1
        else:
            summary["pages_skipped"] += 1
    summary["_truncated_matches"] = truncated_matches
    return summary


# -- CLI --------------------------------------------------------------------------------


def _cmd_find_anchors(args) -> Dict[str, Any]:
    spec = load_spec(args.spec)
    ocr = None
    if args.ocr:
        if not args.tesseract:
            raise UsageError("ocr_unavailable", "--ocr requires --tesseract")
        ocr = OcrOptions(
            tesseract=str(args.tesseract),
            lang=args.ocr_lang,
            dpi=args.ocr_dpi,
            timeout=args.ocr_timeout,
            max_pages=args.ocr_max_pages,
            pages=args.ocr_page or None,
        )
    return find_anchors(
        args.input,
        spec,
        max_pages=args.max_pages,
        max_bytes=args.max_bytes,
        time_budget=args.time_budget,
        min_text_chars=args.min_text_chars,
        ocr=ocr,
    )


def add_anchor_commands(sub, path_type) -> None:
    """F-ANCHOR (roadmap 3.2): ``find-anchors``. Registered additively by cli.py."""
    p = sub.add_parser("find-anchors", help="find anchor markers/literal phrases and return normalized boxes (optional OCR)")
    p.add_argument("--in", dest="input", type=path_type, required=True, help="input PDF")
    p.add_argument("--spec", type=path_type, default=None, help='JSON: {"markers": true, "literals": [{"id": "r1", "text": "..."}], "max_matches": 500}')
    p.add_argument("--max-pages", dest="max_pages", type=int, default=DEFAULT_MAX_PAGES)
    p.add_argument("--max-bytes", dest="max_bytes", type=int, default=DEFAULT_MAX_BYTES)
    p.add_argument("--time-budget", dest="time_budget", type=float, default=DEFAULT_TIME_BUDGET, help="seconds for the whole search")
    p.add_argument("--min-text-chars", dest="min_text_chars", type=int, default=DEFAULT_MIN_TEXT_CHARS, help="below this a page counts as without text")
    p.add_argument("--ocr", action="store_true", help="OCR the pages without text (needs --tesseract)")
    p.add_argument("--tesseract", type=path_type, default=None, help="absolute path of the tesseract binary")
    p.add_argument("--ocr-lang", dest="ocr_lang", default="por")
    p.add_argument("--ocr-dpi", dest="ocr_dpi", type=int, default=OCR_DEFAULT_DPI)
    p.add_argument("--ocr-timeout", dest="ocr_timeout", type=float, default=OCR_DEFAULT_TIMEOUT, help="seconds per page")
    p.add_argument("--ocr-max-pages", dest="ocr_max_pages", type=int, default=OCR_DEFAULT_MAX_PAGES)
    p.add_argument("--ocr-page", dest="ocr_page", type=int, action="append", default=[], help="restrict OCR to this 1-based page; repeatable")
    p.set_defaults(func=_cmd_find_anchors)
