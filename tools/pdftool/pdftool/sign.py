"""``sign`` command: one PAdES B-B signature applied as an incremental update."""

from __future__ import annotations

import logging
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Optional

from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.layout import BoxConstraints
from pyhanko.pdf_utils.misc import PdfError
from pyhanko.pdf_utils.text import TextBoxStyle
from pyhanko.sign import fields, signers
from pyhanko.sign.general import SigningError
from pyhanko.stamp import TextStamp, TextStampStyle

from pdftool.certs import cert_from_asn1, cert_summary, read_passphrase
from pdftool.compose import reject_same_path
from pdftool.errors import InputRejected, ProcessingError, UsageError
from pdftool.geometry import PageMeta, normalized_to_pdf_rect, page_meta_from_pypdf
from pdftool.inspect_cmd import close_reader, open_reader

log = logging.getLogger(__name__)

DEFAULT_FIELD_NAME = "AssinaVelox"
STAMP_TEXT = "Assinado digitalmente por\n%(signer)s\n%(ts)s"
_ROTATION_MATRIX = {
    90: (0, 1, -1, 0, 0, 0),
    180: (-1, 0, 0, -1, 0, 0),
    270: (0, -1, 1, 0, 0, 0),
}


@dataclass(frozen=True)
class VisibleSpec:
    page: int
    x: float
    y: float
    w: float
    h: float


def parse_visible(spec: str) -> VisibleSpec:
    """Parse ``"page,x,y,w,h"`` (page 1-based; x,y,w,h normalized, top-left origin)."""
    parts = [p.strip() for p in str(spec).split(",")]
    if len(parts) != 5:
        raise UsageError("invalid_visible", "--visible must be '<page>,<x>,<y>,<w>,<h>'")
    try:
        page = int(parts[0])
        x, y, w, h = (float(p) for p in parts[1:])
    except ValueError:
        raise UsageError("invalid_visible", "--visible values must be numeric") from None
    if page < 1:
        raise UsageError("invalid_visible", "--visible page must be >= 1")
    if not (0 <= x <= 1 and 0 <= y <= 1) or w <= 0 or h <= 0 or x + w > 1.001 or y + h > 1.001:
        raise UsageError("invalid_visible", "--visible box must lie within the page (normalized 0..1)")
    return VisibleSpec(page, x, y, w, h)


class _RotatedTextStamp(TextStamp):
    """TextStamp whose form XObject carries a ``/Matrix`` that undoes ``/Rotate``.

    pyHanko lays the stamp out in unrotated user space; on a page with
    ``/Rotate 90`` the text would appear sideways. Rotating the appearance
    stream by the same angle (counter-clockwise) makes it upright. The
    viewer maps the transformed BBox onto the annotation /Rect
    (ISO 32000-1, 12.5.5), so the layout box is swapped for 90/270.
    """

    def __init__(self, writer, style, text_params=None, box=None, page_rotation: int = 0):
        super().__init__(writer, style, text_params=text_params, box=box)
        self._page_rotation = page_rotation

    def as_form_xobject(self):
        xobj = super().as_form_xobject()
        matrix = _ROTATION_MATRIX.get(self._page_rotation)
        if matrix:
            xobj[generic.pdf_name("/Matrix")] = generic.ArrayObject([generic.FloatObject(v) for v in matrix])
        return xobj


class RotatedTextStampStyle(TextStampStyle):
    """A :class:`TextStampStyle` aware of the target page's ``/Rotate``."""

    def __init__(self, *args, page_rotation: int = 0, **kwargs):
        super().__init__(*args, **kwargs)
        object.__setattr__(self, "page_rotation", page_rotation)

    def create_stamp(self, writer, box: BoxConstraints, text_params: dict):
        rotation = getattr(self, "page_rotation", 0)
        if rotation in (90, 270) and box is not None and box.width_defined and box.height_defined:
            box = BoxConstraints(width=box.height, height=box.width)
        return _RotatedTextStamp(writer, self, text_params=text_params, box=box, page_rotation=rotation)


def _choose_field_name(reader, requested: Optional[str]) -> str:
    """Pick the field to sign: the requested/default name, or a numbered variant if it is taken.

    An existing *empty* signature field with that name is reused (pre-placed
    fields); an existing *signed* field gets a ``_2``, ``_3``... suffix so
    that documents can be signed by several people with default settings.
    """
    filled: Dict[str, bool] = {}
    try:
        for name, value, _ref in fields.enumerate_sig_fields(reader):
            filled[str(name)] = value is not None
    except Exception as exc:  # noqa: BLE001 - best effort; pyHanko re-checks anyway
        log.warning("could not enumerate signature fields: %s", exc)
    base = (requested or "").strip() or DEFAULT_FIELD_NAME
    if not filled.get(base, False):
        return base
    index = 2
    while filled.get(f"{base}_{index}", False):
        index += 1
    return f"{base}_{index}"


def _build_stamp_style(page_meta: PageMeta) -> RotatedTextStampStyle:
    return RotatedTextStampStyle(
        stamp_text=STAMP_TEXT,
        border_width=1,
        text_box_style=TextBoxStyle(font_size=8),
        page_rotation=page_meta.rotation,
    )


def sign_pdf(
    in_path: Path,
    out_path: Path,
    pfx_path: Path,
    pass_env: str,
    field_name: Optional[str] = None,
    reason: Optional[str] = None,
    location: Optional[str] = None,
    contact: Optional[str] = None,
    visible: Optional[str] = None,
    tsa_pfx: Optional[Path] = None,
    tsa_pass_env: Optional[str] = None,
    tsa_serial: Optional[int] = None,
    tsa_policy_oid: Optional[str] = None,
    tsa_accuracy_ms: int = 1000,
) -> Dict[str, Any]:
    # Optional signature time stamp from the OPERATOR TSA (K-TSA, docs/fase-2/carimbo-e-dossie.md).
    # The declared profile below stays "PAdES-B-B" (roadmap T2); the fact is reported apart.
    timestamper = None
    if tsa_pfx is not None:
        if tsa_pass_env is None or tsa_serial is None or tsa_policy_oid is None:
            raise UsageError("tsa_options_incomplete", "--tsa-pfx requires --tsa-pass-env, --tsa-serial and --tsa-policy-oid")
        from pdftool.timestamp import operator_timestamper

        timestamper = operator_timestamper(tsa_pfx, tsa_pass_env, tsa_serial, tsa_policy_oid, tsa_accuracy_ms)
    passphrase = read_passphrase(pass_env)
    if not pfx_path.is_file():
        raise InputRejected("pfx_not_found", f"PKCS#12 file not found: {pfx_path}")
    reject_same_path(in_path, out_path)
    visible_spec = parse_visible(visible) if visible else None

    # Pre-flight with pypdf: rejects corrupt/encrypted input, yields page geometry.
    reader, info = open_reader(in_path)
    try:
        if info["encrypted"]:
            raise InputRejected("encrypted_pdf", "signing encrypted PDFs is not supported")
        page_count = len(reader.pages)
        page_meta: Optional[PageMeta] = None
        if visible_spec is not None:
            if visible_spec.page > page_count:
                raise UsageError("invalid_visible", f"page {visible_spec.page} out of range (document has {page_count} pages)")
            page_meta = page_meta_from_pypdf(reader.pages[visible_spec.page - 1])
    finally:
        close_reader(reader)

    signer = signers.SimpleSigner.load_pkcs12(str(pfx_path), passphrase=passphrase.encode("utf-8"))
    del passphrase
    if signer is None:
        raise InputRejected("pfx_load_failed", "could not load PKCS#12: wrong passphrase or unsupported file")

    with in_path.open("rb") as inf:
        try:
            writer = IncrementalPdfFileWriter(inf, strict=False)
        except Exception as exc:  # noqa: BLE001 - pyHanko's parser is stricter than pypdf's
            raise InputRejected("invalid_pdf", f"pyHanko cannot parse PDF: {type(exc).__name__}: {exc}") from exc

        name = _choose_field_name(writer.prev, field_name)
        meta = signers.PdfSignatureMetadata(
            field_name=name,
            md_algorithm="sha256",
            subfilter=fields.SigSeedSubFilter.PADES,
            use_pades_lta=False,
            embed_validation_info=False,
            reason=reason or None,
            location=location or None,
            contact_info=contact or None,
        )
        stamp_style = None
        new_field_spec = None
        if visible_spec is not None and page_meta is not None:
            llx, lly, urx, ury = normalized_to_pdf_rect(page_meta, visible_spec.x, visible_spec.y, visible_spec.w, visible_spec.h)
            new_field_spec = fields.SigFieldSpec(
                sig_field_name=name,
                on_page=visible_spec.page - 1,
                box=(int(round(llx)), int(round(lly)), int(round(urx)), int(round(ury))),
            )
            stamp_style = _build_stamp_style(page_meta)

        pdf_signer = signers.PdfSigner(meta, signer, stamp_style=stamp_style, new_field_spec=new_field_spec, timestamper=timestamper)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        try:
            with out_path.open("wb") as outf:
                pdf_signer.sign_pdf(writer, output=outf)
        except SigningError as exc:
            out_path.unlink(missing_ok=True)
            raise ProcessingError("signing_failed", f"pyHanko refused to sign: {exc}") from exc
        except (PdfError, ValueError, TypeError, KeyError) as exc:
            out_path.unlink(missing_ok=True)
            raise ProcessingError("signing_failed", f"signing failed: {type(exc).__name__}: {exc}") from exc
        except OSError as exc:
            out_path.unlink(missing_ok=True)
            raise ProcessingError("pdf_write_failed", f"could not write output: {exc}") from exc

    summary = cert_summary(cert_from_asn1(signer.signing_cert))
    return {
        "ok": True,
        "profile": "PAdES-B-B",
        "field_name": name,
        "signer_subject": summary["subject"],
        "issuer": summary["issuer"],
        "serial_hex": summary["serial_hex"],
        "cert_fingerprint_sha256": summary["cert_fingerprint_sha256"],
        "not_before": summary["not_before"],
        "not_after": summary["not_after"],
        "md_algorithm": "sha256",
        "timestamp": None,
        "visible": visible_spec is not None,
        "page_count": page_count,
        # K-TSA: facts about an embedded signature time stamp (None without --tsa-pfx). Never
        # changes "profile": B-T is not announced before the roadmap T2 checklist is met.
        "signature_timestamp": timestamper.report() if timestamper is not None else None,
    }
