"""``verify-incremental``: does a returned PDF extend the EXPECTED revision with exactly one
new signature and nothing else?

P3-GOV (roadmap 3.5, alternative flow): the participant downloads the revision we reserved,
signs it OUTSIDE the platform (e.g. on the gov.br portal) and sends the file back. Accepting
"any PDF with a valid signature" would let anyone swap the document. This command answers,
offline and without trusting anything in the returned file:

(a) **binding** - the expected revision's bytes are an exact prefix of the returned file
    (incremental update), and exactly ONE revision was appended;
(b) **one new signature, sound, alone** - exactly one new signature, intact and valid,
    covering the ENTIRE file; the changes since the expected revision are only the ones a
    signature brings (pyHanko difference analysis: ``NONE``/``FORM_FILLING`` by default);
    every older signature still intact/valid with permitted later changes; no DocMDP
    violation; a certification signature that forbids any later change (``P=1``) is refused
    because the other participants and the operator still have to sign after it;
(c) **trust** - with ``--trust`` roots, the new signature must chain up to one of them
    (``chain_not_trusted`` otherwise); without roots trust is reported as not verified;
(d) **identity** - when the caller passes the participant's CPF through a NAMED environment
    variable (``--expect-cpf-env``; never argv), the CPF the certificate declares is compared
    here and only ``match``/``mismatch``/``unknown`` plus the MASKED CPF leave the process.

Policy rejections are NOT errors: the command exits 0 with ``accepted: false`` and the list
of ``problems`` (stable codes). Exit 4 only when a file is missing, unreadable or encrypted.
"""

from __future__ import annotations

import hashlib
import logging
import os
import re
from decimal import Decimal
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence

from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.fields import MDPPerm

from pdftool.certs import cert_from_asn1, cert_summary
from pdftool.errors import InputRejected, UsageError
from pdftool.inspect_cert import TEST_MARKER, holder_facts
from pdftool.validate import validate_pdf

log = logging.getLogger(__name__)

DEFAULT_PERMITTED_LEVELS = ("NONE", "FORM_FILLING")
ALLOWED_LEVEL_CHOICES = ("NONE", "FORM_FILLING", "ANNOTATIONS")
_ENV_NAME_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")

# Order matters: the first problem is the one the caller shows to the participant.
PROBLEM_ORDER = (
    "base_not_prefix",
    "no_new_revision",
    "unexpected_revision_count",
    "no_new_signature",
    "multiple_new_signatures",
    "unexpected_document_timestamp",
    "signature_not_intact",
    "signature_invalid",
    "signature_not_covering_file",
    "unpermitted_changes",
    "previous_signature_broken",
    "docmdp_violation",
    "docmdp_locks_document",
    "chain_not_trusted",
    "holder_mismatch",
)


def _sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _read(path: Path, label: str) -> bytes:
    if not path.is_file():
        raise InputRejected("missing_input", f"{label} file not found: {path.name}")
    return path.read_bytes()


def _open(path: Path, label: str):
    handle = path.open("rb")
    try:
        reader = PdfFileReader(handle, strict=False)
        if reader.encrypted:
            raise InputRejected("encrypted_pdf", f"{label} PDF is encrypted")
    except InputRejected:
        handle.close()
        raise
    except Exception as exc:  # noqa: BLE001 - hostile input: any parser failure is a rejection
        handle.close()
        raise InputRejected("invalid_pdf", f"cannot parse {label} PDF: {type(exc).__name__}") from exc
    return handle, reader


def _signatures(reader, label: str) -> List[Any]:
    try:
        return list(reader.embedded_regular_signatures)
    except Exception as exc:  # noqa: BLE001
        raise InputRejected("invalid_pdf", f"cannot enumerate signatures of the {label} PDF: {type(exc).__name__}") from exc


def _timestamps(reader) -> List[Any]:
    try:
        return list(reader.embedded_timestamp_signatures)
    except Exception:  # noqa: BLE001 - treated as none; regular signatures already parsed
        return []


def _read_expected_cpf(env_name: Optional[str]) -> Optional[str]:
    if not env_name:
        return None
    if not _ENV_NAME_RE.match(env_name):
        raise UsageError("invalid_env_name", "--expect-cpf-env must be an environment variable NAME")
    value = re.sub(r"\D", "", os.environ.get(env_name, ""))
    return value if len(value) == 11 else None


_CPF_IN_TEXT_RE = re.compile(r"(?<!\d)\d{3}\.?(\d{3})\.?(\d{3})-?\d{2}(?!\d)")


def _mask_cpfs(text: Optional[str]) -> Optional[str]:
    """A CPF inside a certificate name (e-CPF convention ``NOME:CPF``) leaves only masked."""
    if text is None:
        return None
    return _CPF_IN_TEXT_RE.sub(lambda m: f"***.{m.group(1)}.{m.group(2)}-**", str(text))


def _is_test_certificate(summary: Dict[str, Any]) -> bool:
    return TEST_MARKER in str(summary.get("subject") or "").upper() or TEST_MARKER in str(summary.get("issuer") or "").upper()


def _docmdp(sig) -> Optional[int]:
    try:
        level = sig.docmdp_level
    except Exception:  # noqa: BLE001
        return None
    if level is None:
        return None
    return int(level.value) if isinstance(level, MDPPerm) else int(level)


# Catalog keys a signature revision may add or change. ``/Perms`` only for a certification
# signature (checked separately); ``/Extensions``/``/Version`` are written by PAdES signers.
_CATALOG_SIGNATURE_KEYS = {"/AcroForm", "/Extensions", "/Version"}
_ACROFORM_SIGNATURE_KEYS = {"/Fields", "/SigFlags"}
_MAX_PAGES_WALKED = 20000


def _canon(obj, depth: int = 0):
    """Comparable form of a PDF value WITHOUT dereferencing indirect references."""
    if depth > 64:
        return ("too_deep",)
    if isinstance(obj, generic.IndirectObject):
        return ("ref", obj.idnum, obj.generation)
    if isinstance(obj, generic.StreamObject):
        items = tuple(sorted((str(k), _canon(v, depth + 1)) for k, v in dict.items(obj)))
        return ("stream", items, hashlib.sha256(obj.encoded_data or b"").hexdigest())
    if isinstance(obj, generic.DictionaryObject):
        return ("dict", tuple(sorted((str(k), _canon(v, depth + 1)) for k, v in dict.items(obj))))
    if isinstance(obj, generic.ArrayObject):
        return ("array", tuple(_canon(v, depth + 1) for v in list.__iter__(obj)))
    if isinstance(obj, generic.BooleanObject) or isinstance(obj, bool):
        return ("bool", bool(obj))
    if isinstance(obj, (int, float, Decimal)):
        # By VALUE: a writer that re-serialises an untouched page turns "595.280" into
        # "595.28" (dompdf bases do that) - same number, not a modification.
        return ("number", Decimal(str(obj)).normalize())
    return ("value", type(obj).__name__, str(obj))


def _resolve(value):
    return value.get_object() if isinstance(value, generic.IndirectObject) else value


def _ref_of(value) -> Optional[tuple]:
    return (value.idnum, value.generation) if isinstance(value, generic.IndirectObject) else None


def _raw_list(value) -> List[Any]:
    value = _resolve(value)
    return list(list.__iter__(value)) if isinstance(value, generic.ArrayObject) else []


def _pages(root) -> List[Dict[str, Any]]:
    """Page leaves in order: their reference and the raw ``/Annots`` value."""
    found: List[Dict[str, Any]] = []
    stack = [dict.get(root, "/Pages")]
    seen = set()
    while stack and len(found) < _MAX_PAGES_WALKED:
        raw = stack.pop()
        ref = _ref_of(raw)
        if ref is not None:
            if ref in seen:
                continue
            seen.add(ref)
        node = _resolve(raw)
        if not isinstance(node, generic.DictionaryObject):
            continue
        if str(dict.get(node, "/Type")) == "/Page" or "/Kids" not in node:
            found.append({"ref": ref, "annots": dict.get(node, "/Annots")})
            continue
        stack.extend(reversed(_raw_list(dict.get(node, "/Kids"))))
    return found


def _dict_changes(old, new, allowed: set) -> List[str]:
    old = old if isinstance(old, generic.DictionaryObject) else generic.DictionaryObject()
    new = new if isinstance(new, generic.DictionaryObject) else generic.DictionaryObject()
    changed = []
    for key in sorted(set(dict.keys(old)) | set(dict.keys(new)), key=str):
        if _canon(dict.get(old, key)) != _canon(dict.get(new, key)) and str(key) not in allowed:
            changed.append(str(key))
    return changed


def _appended(old_items: List[Any], new_items: List[Any]) -> Optional[List[Any]]:
    """New items if ``new`` keeps every old item (same references, same order); else None."""
    old_canon = [_canon(item) for item in old_items]
    new_canon = [_canon(item) for item in new_items]
    if new_canon[: len(old_canon)] != old_canon:
        return None
    return new_items[len(old_items):]


def _signature_revision_changes(reader, base_revision_index: int, newest, is_certification: bool) -> Dict[str, Any]:
    """What the ONE appended revision changed, object by object.

    pyHanko's difference analysis reviews changes made AFTER a signed revision; here the
    base is usually unsigned (no AcroForm yet) and the question is the opposite one: does
    the signing revision itself bring anything besides the signature? Allowed, and only
    these: the new signature field + its widget (+ appearance), the AcroForm growing by that
    field, the catalog gaining ``/AcroForm``/``/Extensions``/``/Version`` (``/Perms`` for a
    certification signature), a page's ``/Annots`` growing by the widget, and document
    metadata (``/Info`` / catalog ``/Metadata``). Any other existing object overwritten, a
    page added/removed, another field or a non-widget annotation is reported.
    """
    findings: List[str] = []
    notes: List[str] = []
    rev = int(reader.xrefs.total_revisions) - 1
    old = reader.get_historical_resolver(base_revision_index)

    if reader.xrefs.refs_freed_in_revision(rev):
        findings.append("objects_freed")
    if _ref_of_reference(old.root_ref) != _ref_of_reference(reader.root_ref):
        findings.append("catalog_replaced")

    old_root, new_root = old.root, reader.root
    old_info = _ref_of(dict.get(old.trailer_view, "/Info"))
    old_metadata = _ref_of(dict.get(old_root, "/Metadata"))
    old_acroform_raw = dict.get(old_root, "/AcroForm")
    new_acroform_raw = dict.get(new_root, "/AcroForm")
    old_acroform = _resolve(old_acroform_raw) if old_acroform_raw is not None else None
    new_acroform = _resolve(new_acroform_raw) if new_acroform_raw is not None else None
    old_fields_raw = dict.get(old_acroform, "/Fields") if isinstance(old_acroform, generic.DictionaryObject) else None
    old_pages, new_pages = _pages(old_root), _pages(new_root)
    if [page["ref"] for page in old_pages] != [page["ref"] for page in new_pages]:
        findings.append("pages_changed")
    page_by_ref = {page["ref"]: (page, new) for page, new in zip(old_pages, new_pages) if page["ref"] is not None}
    annots_arrays = {_ref_of(page["annots"]): (page, new) for page, new in zip(old_pages, new_pages) if _ref_of(page["annots"])}

    # 1. every EXISTING object the revision overwrote
    for reference in reader.xrefs.explicit_refs_in_revision(rev):
        if int(reader.xrefs.get_introducing_revision(reference)) >= rev:
            continue  # new object: judged by whoever references it (below)
        key = (reference.idnum, reference.generation)
        try:
            old_obj, new_obj = old.get_object(reference), reader.get_object(reference)
        except Exception:  # noqa: BLE001
            findings.append(f"unreadable_object:{reference.idnum}")
            continue
        if key == _ref_of_reference(reader.root_ref):
            allowed = set(_CATALOG_SIGNATURE_KEYS) | ({"/Perms"} if is_certification else set()) | {"/Metadata"}
            for name in _dict_changes(old_obj, new_obj, allowed):
                findings.append(f"catalog_changed:{name}")
            if "/Perms" in old_obj and _canon(dict.get(old_obj, "/Perms")) != _canon(dict.get(new_obj, "/Perms")):
                findings.append("catalog_changed:/Perms")
            if dict.get(old_obj, "/Metadata") is not None and \
                    _canon(dict.get(old_obj, "/Metadata")) != _canon(dict.get(new_obj, "/Metadata")):
                notes.append("metadata_replaced")
        elif key == old_info or key == old_metadata:
            notes.append("metadata_updated")
        elif key == _ref_of(old_acroform_raw):
            for name in _dict_changes(old_obj, new_obj, _ACROFORM_SIGNATURE_KEYS):
                findings.append(f"acroform_changed:{name}")
        elif key == _ref_of(old_fields_raw):
            pass  # compared with the AcroForm below
        elif key in page_by_ref:
            for name in _dict_changes(old_obj, new_obj, {"/Annots"}):
                findings.append(f"page_changed:{name}")
        elif key in annots_arrays:
            pass  # compared with the page below
        else:
            findings.append(f"existing_object_modified:{reference.idnum}")

    # 2. fields: the old ones kept, exactly one added, and it is the new signature's field
    new_fields = _appended(_raw_list(old_fields_raw), _raw_list(dict.get(new_acroform, "/Fields") if isinstance(new_acroform, generic.DictionaryObject) else None))
    signature_value = _ref_of(dict.get(newest.sig_field, "/V")) if newest is not None else None
    field_refs = set()
    if new_fields is None:
        findings.append("fields_removed_or_reordered")
    else:
        for raw in new_fields:
            field = _resolve(raw)
            is_new_signature = isinstance(field, generic.DictionaryObject) and str(dict.get(field, "/FT")) == "/Sig" \
                and signature_value is not None and _ref_of(dict.get(field, "/V")) == signature_value
            if not is_new_signature:
                findings.append("unexpected_field_added")
            elif _ref_of(raw) is not None:
                field_refs.add(_ref_of(raw))
        if len(new_fields) != 1:
            findings.append("unexpected_field_count")

    # 3. annotations: only the widget(s) of the new signature field
    for old_page, new_page in zip(old_pages, new_pages):
        added = _appended(_raw_list(old_page["annots"]), _raw_list(new_page["annots"]))
        if added is None:
            findings.append("annotations_removed_or_reordered")
            continue
        for raw in added:
            annot = _resolve(raw)
            if not isinstance(annot, generic.DictionaryObject):
                findings.append("invalid_annotation")
                continue
            if str(dict.get(annot, "/Subtype")) != "/Widget":
                findings.append("annotation_added")
                continue
            owner = _ref_of(raw) if _ref_of(dict.get(annot, "/Parent")) is None else _ref_of(dict.get(annot, "/Parent"))
            if owner not in field_refs:
                findings.append("unexpected_widget_added")

    # 4. certification: /Perms must point at the NEW signature
    if is_certification:
        perms = _resolve(dict.get(new_root, "/Perms"))
        docmdp_ref = _ref_of(dict.get(perms, "/DocMDP")) if isinstance(perms, generic.DictionaryObject) else None
        if docmdp_ref is None or docmdp_ref != signature_value:
            findings.append("certification_not_bound_to_new_signature")
    elif "/Perms" in new_root and "/Perms" not in old_root:
        findings.append("catalog_changed:/Perms")

    unique = list(dict.fromkeys(findings))
    if not unique:
        level = "FORM_FILLING"
    elif set(unique) == {"annotation_added"}:
        level = "ANNOTATIONS"
    else:
        level = "OTHER"
    return {
        "modification_level": level,
        "suspicious": level == "OTHER",
        "detail": unique[:20] or None,
        "notes": list(dict.fromkeys(notes)) or None,
    }


def _ref_of_reference(reference) -> Optional[tuple]:
    if reference is None:
        return None
    return (reference.idnum, reference.generation)


def verify_incremental(
    base_path: Path,
    in_path: Path,
    trust_paths: Optional[Sequence[Path]] = None,
    permitted_levels: Optional[Sequence[str]] = None,
    expect_cpf_env: Optional[str] = None,
) -> Dict[str, Any]:
    permitted = tuple(dict.fromkeys(level.upper() for level in (permitted_levels or DEFAULT_PERMITTED_LEVELS)))
    for level in permitted:
        if level not in ALLOWED_LEVEL_CHOICES:
            raise UsageError("invalid_permitted_level", f"--permitted-level must be one of {', '.join(ALLOWED_LEVEL_CHOICES)}")
    expected_cpf = _read_expected_cpf(expect_cpf_env)
    trust_list = list(trust_paths or [])

    base_bytes = _read(base_path, "base")
    returned_bytes = _read(in_path, "returned")
    prefix_preserved = len(returned_bytes) > len(base_bytes) and returned_bytes[: len(base_bytes)] == base_bytes

    base_handle, base_reader = _open(base_path, "base")
    try:
        base_revisions = int(base_reader.xrefs.total_revisions)
        base_signature_count = len(_signatures(base_reader, "base"))
        base_timestamp_count = len(_timestamps(base_reader))
    finally:
        base_handle.close()

    result: Dict[str, Any] = {
        "ok": True,
        "accepted": False,
        "problems": [],
        "permitted_levels": list(permitted),
        "base": {
            "size": len(base_bytes),
            "sha256": _sha256(base_bytes),
            "revisions": base_revisions,
            "signature_count": base_signature_count,
        },
        "returned": {
            "size": len(returned_bytes),
            "sha256": _sha256(returned_bytes),
            "revisions": None,
            "signature_count": None,
        },
        "prefix_preserved": prefix_preserved,
        "new_revisions": None,
        "new_signature_count": None,
        "signature": None,
        "changes_since_base": None,
        "previous_signatures_ok": None,
        "trust_roots_configured": 0,
        "revocation": "not_checked",
    }
    problems: List[str] = result["problems"]
    del base_bytes

    if not prefix_preserved:
        # Not the file we handed out (or the portal rewrote it). Nothing else is meaningful:
        # the signature, whatever it is, does not cover the revision we reserved.
        problems.append("base_not_prefix")
        return _finish(result)

    handle, reader = _open(in_path, "returned")
    try:
        total_revisions = int(reader.xrefs.total_revisions)
        signatures = _signatures(reader, "returned")
        timestamps = _timestamps(reader)
        result["returned"]["revisions"] = total_revisions
        result["returned"]["signature_count"] = len(signatures)
        new_revisions = total_revisions - base_revisions
        result["new_revisions"] = new_revisions
        if new_revisions <= 0:
            problems.append("no_new_revision")
        elif new_revisions != 1:
            problems.append("unexpected_revision_count")

        new_signatures = [sig for sig in signatures if int(getattr(sig, "signed_revision", -1)) >= base_revisions]
        result["new_signature_count"] = len(new_signatures)
        if len(signatures) - base_signature_count != len(new_signatures):
            # A signature of the base disappeared or moved: the file is not the base + more.
            problems.append("previous_signature_broken")
        if not new_signatures:
            problems.append("no_new_signature")
        elif len(new_signatures) > 1:
            problems.append("multiple_new_signatures")
        if len(timestamps) > base_timestamp_count:
            problems.append("unexpected_document_timestamp")

        newest = new_signatures[-1] if new_signatures else None
        docmdp = _docmdp(newest) if newest is not None else None

        # Only meaningful for the shape we accept (one revision, one signature); any other
        # shape is already refused above.
        changes = None
        if new_revisions == 1 and len(new_signatures) == 1:
            try:
                changes = _signature_revision_changes(reader, base_revisions - 1, newest, docmdp is not None)
            except Exception as exc:  # noqa: BLE001 - inconclusive analysis is never "no change"
                changes = {"modification_level": None, "suspicious": True,
                           "detail": [f"analysis_failed: {type(exc).__name__}"], "notes": None}
        result["changes_since_base"] = changes
        if changes is not None and (changes["suspicious"] or changes["modification_level"] not in permitted):
            problems.append("unpermitted_changes")
        cert_facts: Dict[str, Any] = {}
        holder: Dict[str, Any] = {}
        if newest is not None:
            try:
                certificate = cert_from_asn1(newest.signer_cert)
                cert_facts = cert_summary(certificate)
                facts = holder_facts(certificate)
                declared = facts.get("cpf")
                if expected_cpf is None or declared is None:
                    cpf_match = "unknown"
                else:
                    cpf_match = "match" if declared == expected_cpf else "mismatch"
                holder = {
                    "name": facts.get("name"),
                    "cpf_masked": facts.get("cpf_masked"),
                    "cpf_source": facts.get("cpf_source"),
                    "cpf_present": declared is not None,
                    "cpf_match": cpf_match,
                    "cpf_confirmed": False,
                }
                del declared, facts
            except Exception as exc:  # noqa: BLE001 - a signature without a readable certificate is not sound
                log.warning("verify-incremental: signer certificate unreadable: %s", type(exc).__name__)
                problems.append("signature_invalid")
    finally:
        handle.close()

    validation = validate_pdf(in_path, trust_paths=trust_list or None)
    result["trust_roots_configured"] = validation["trust_roots_configured"]
    by_name = {entry["field_name"]: entry for entry in validation["signatures"]}

    previous_ok = True
    for sig in signatures:
        if newest is not None and sig is newest:
            continue
        entry = by_name.get(str(sig.field_name))
        if entry is None or not (entry["intact"] and entry["valid"]) or entry["docmdp_ok"] is False \
                or entry["modification_level"] not in permitted:
            previous_ok = False
    result["previous_signatures_ok"] = previous_ok
    if not previous_ok:
        problems.append("previous_signature_broken")

    if newest is not None:
        entry = by_name.get(str(newest.field_name)) or {}
        if not entry.get("intact"):
            problems.append("signature_not_intact")
        if not entry.get("valid"):
            problems.append("signature_invalid")
        if entry.get("coverage") != "ENTIRE_FILE":
            problems.append("signature_not_covering_file")
        if entry.get("docmdp_ok") is False:
            problems.append("docmdp_violation")
        if docmdp == int(MDPPerm.NO_CHANGES.value):
            problems.append("docmdp_locks_document")
        trusted = bool(entry.get("trusted"))
        if trust_list and not trusted:
            problems.append("chain_not_trusted")
        if holder.get("cpf_match") == "mismatch":
            problems.append("holder_mismatch")
        result["signature"] = {
            "field_name": str(newest.field_name),
            "intact": bool(entry.get("intact")),
            "valid": bool(entry.get("valid")),
            "trusted": trusted,
            "trust_reason": entry.get("trust_reason"),
            "coverage": entry.get("coverage"),
            "modification_level": entry.get("modification_level"),
            "docmdp_ok": entry.get("docmdp_ok"),
            "is_certification": docmdp is not None,
            "docmdp_permission": docmdp,
            "subfilter": entry.get("subfilter"),
            "md_algorithm": entry.get("md_algorithm"),
            "signing_time": entry.get("signing_time"),
            "signer_subject": _mask_cpfs(cert_facts.get("subject")),
            "issuer": _mask_cpfs(cert_facts.get("issuer")),
            "serial_hex": cert_facts.get("serial_hex"),
            "cert_fingerprint_sha256": cert_facts.get("cert_fingerprint_sha256"),
            "not_before": cert_facts.get("not_before"),
            "not_after": cert_facts.get("not_after"),
            "test_certificate": _is_test_certificate(cert_facts) if cert_facts else False,
            "holder": holder or None,
            "errors": [_mask_cpfs(str(error)) for error in (entry.get("errors") or [])],
        }

    return _finish(result)


def _finish(result: Dict[str, Any]) -> Dict[str, Any]:
    unique = list(dict.fromkeys(result["problems"]))
    unique.sort(key=lambda code: PROBLEM_ORDER.index(code) if code in PROBLEM_ORDER else len(PROBLEM_ORDER))
    result["problems"] = unique
    result["accepted"] = not unique
    return result
