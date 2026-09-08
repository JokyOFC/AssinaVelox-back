"""``inspect`` command plus the shared pypdf-based PDF opening helpers."""

from __future__ import annotations

import logging
import re
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from pypdf import PdfReader
from pypdf.constants import UserAccessPermissions

from pdftool.errors import InputRejected
from pdftool.geometry import page_meta_from_pypdf

try:  # pypdf >= 3 exports PasswordType at top level; keep a fallback anyway.
    from pypdf import PasswordType
except ImportError:  # pragma: no cover
    from pypdf._encryption import PasswordType  # type: ignore

log = logging.getLogger(__name__)

_MAX_FIELD_DEPTH = 64
_META_KEYS = ("title", "author", "producer", "creator")


def _resolve(obj):
    """Follow indirect references; return ``None`` for PDF null objects."""
    try:
        obj = obj.get_object()
    except AttributeError:
        pass
    if obj is None or obj.__class__.__name__ == "NullObject":
        return None
    return obj


def open_reader(path: Path) -> Tuple[PdfReader, Dict[str, Any]]:
    """Open ``path`` for processing.

    Raises :class:`InputRejected` with code ``missing_input``, ``invalid_pdf``
    or ``encrypted_pdf`` (when an empty user password does not open the
    file). Returns ``(reader, info)`` where ``info`` carries ``encrypted`` and
    ``permissions``. Callers must :func:`close_reader` when done.
    """
    if not path.is_file():
        raise InputRejected("missing_input", f"input file not found: {path}")
    try:
        reader = PdfReader(str(path), strict=False)
    except Exception as exc:  # noqa: BLE001 - pypdf raises many exception types
        raise InputRejected("invalid_pdf", f"cannot parse PDF: {type(exc).__name__}: {exc}") from exc

    encrypted = bool(reader.is_encrypted)
    permissions: Optional[List[str]] = None
    if encrypted:
        try:
            result = reader.decrypt("")
        except Exception as exc:  # noqa: BLE001 - unsupported filter, broken /Encrypt
            close_reader(reader)
            raise InputRejected(
                "encrypted_pdf", f"PDF is encrypted and cannot be opened: {type(exc).__name__}"
            ) from exc
        if result == PasswordType.NOT_DECRYPTED:
            close_reader(reader)
            raise InputRejected("encrypted_pdf", "PDF is encrypted; it cannot be opened without a password")
        permissions = _permissions(reader)

    try:
        count = len(reader.pages)
        for page in reader.pages:
            _ = page.mediabox  # force page-tree resolution to surface corruption early
    except Exception as exc:  # noqa: BLE001
        close_reader(reader)
        raise InputRejected("invalid_pdf", f"cannot read page tree: {type(exc).__name__}: {exc}") from exc
    if count == 0:
        close_reader(reader)
        raise InputRejected("invalid_pdf", "PDF has no pages")
    return reader, {"encrypted": encrypted, "permissions": permissions}


def close_reader(reader: PdfReader) -> None:
    stream = getattr(reader, "stream", None)
    try:
        if stream is not None:
            stream.close()
    except Exception:  # noqa: BLE001
        pass


def _permissions(reader: PdfReader) -> Optional[List[str]]:
    try:
        perms = reader.user_access_permissions
    except Exception:  # noqa: BLE001
        return None
    if perms is None:
        return None
    value = int(perms)
    names = []
    for member in UserAccessPermissions:
        if re.fullmatch(r"R\d+", member.name):
            continue  # reserved bits
        if value & int(member.value):
            names.append(member.name.lower())
    return names


def _walk_fields(node, parent_name: Optional[str], parent_ft: Optional[str], acc: List[str], depth: int = 0) -> None:
    if depth > _MAX_FIELD_DEPTH:
        return
    obj = _resolve(node)
    if not isinstance(obj, dict):
        return
    t = _resolve(obj.get("/T"))
    name = str(t) if t is not None else None
    if parent_name and name:
        full = f"{parent_name}.{name}"
    else:
        full = name or parent_name
    ft = _resolve(obj.get("/FT"))
    ft_str = str(ft) if ft is not None else parent_ft
    kids = _resolve(obj.get("/Kids"))
    if kids:
        for kid in kids:
            _walk_fields(kid, full, ft_str, acc, depth + 1)
    if ft_str == "/Sig" and _resolve(obj.get("/V")) is not None:
        label = full or "(unnamed)"
        if label not in acc:
            acc.append(label)


def _acroform(reader: PdfReader):
    try:
        root = _resolve(reader.trailer["/Root"])
        return _resolve(root.get("/AcroForm")) if isinstance(root, dict) else None
    except Exception:  # noqa: BLE001
        return None


def detect_signature_fields(reader: PdfReader) -> List[str]:
    """Fully-qualified names of ``/FT /Sig`` fields that carry a ``/V`` value."""
    acro = _acroform(reader)
    if not isinstance(acro, dict):
        return []
    acc: List[str] = []
    try:
        fields = _resolve(acro.get("/Fields")) or []
        for field in fields:
            _walk_fields(field, None, None, acc)
    except Exception as exc:  # noqa: BLE001
        log.warning("signature field walk failed: %s", exc)
    return acc


def _pdf_version(reader: PdfReader) -> str:
    header = ""
    try:
        header = str(reader.pdf_header or "")
    except Exception:  # noqa: BLE001
        pass
    version = header.replace("%PDF-", "").strip() or "unknown"
    try:
        root = _resolve(reader.trailer["/Root"])
        cat_version = _resolve(root.get("/Version")) if isinstance(root, dict) else None
        if cat_version is not None:
            cat = str(cat_version).lstrip("/")
            if float(cat) > float(version):
                version = cat
    except Exception:  # noqa: BLE001
        pass
    return version


def _clean_text(value) -> Optional[str]:
    if value is None:
        return None
    if isinstance(value, bytes):
        value = value.decode("utf-8", errors="replace")
    text = str(value).replace("\x00", "").strip()
    return text[:1000] if text else None


def _metadata(reader: PdfReader) -> Dict[str, Optional[str]]:
    out: Dict[str, Optional[str]] = {k: None for k in _META_KEYS}
    try:
        meta = reader.metadata
    except Exception:  # noqa: BLE001
        meta = None
    if meta is None:
        return out
    for key in _META_KEYS:
        try:
            out[key] = _clean_text(getattr(meta, key))
        except Exception:  # noqa: BLE001
            out[key] = None
    return out


def page_entries(reader: PdfReader) -> List[Dict[str, Any]]:
    entries = []
    for index, page in enumerate(reader.pages, start=1):
        meta = page_meta_from_pypdf(page)
        width, height = meta.displayed_size
        entries.append(
            {
                "index": index,
                "rotation": meta.rotation,
                "mediabox": [round(v, 4) for v in meta.mediabox],
                "cropbox": [round(v, 4) for v in meta.cropbox],
                "width_pt": round(width, 4),
                "height_pt": round(height, 4),
            }
        )
    return entries


def inspect_pdf(path: Path) -> Dict[str, Any]:
    reader, info = open_reader(path)
    try:
        acro = _acroform(reader)
        sig_fields = detect_signature_fields(reader)
        result: Dict[str, Any] = {
            "ok": True,
            "pdf_version": _pdf_version(reader),
            "page_count": len(reader.pages),
            "encrypted": info["encrypted"],
            "openable": True,
            "has_signatures": bool(sig_fields),
            "signature_count": len(sig_fields),
            "signature_fields": sig_fields,
            "has_acroform": isinstance(acro, dict),
            "has_xfa": isinstance(acro, dict) and _resolve(acro.get("/XFA")) is not None,
            "metadata": _metadata(reader),
            "pages": page_entries(reader),
        }
        if info["encrypted"]:
            result["permissions"] = info["permissions"]
        return result
    finally:
        close_reader(reader)
