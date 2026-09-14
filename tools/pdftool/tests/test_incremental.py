"""``verify-incremental`` (P3-GOV): a PDF signed OUTSIDE the platform and returned.

Every "returned" file here is produced by a small simulator of what a signing portal would
do with the revision we handed out: an incremental update with one PAdES signature made with
a TEST certificate (throw-away TEST CA, CN with "TESTE" - never gov.br, never ICP-Brasil).
The real portal output is NOT CONFIRMED (docs/integracoes/gov-br-assinatura.md 6.1); these
tests pin down the acceptance rule, not the portal.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from pypdf import PdfReader, PdfWriter
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import fields, signers
from pyhanko.sign.fields import MDPPerm

from conftest import make_pdf
from pdftool.errors import InputRejected
from pdftool.incremental import verify_incremental
from pdftool.inspect_cert import generate_participant_test_cert, load_participant_pkcs12
from pdftool.participant_sign import _signer_from, participant_sign

PASS_ENV = "PDFTOOL_GOVBR_TEST_PASS"
PASSWORD = "senha-portal-teste-9!"
CPF = "52998224725"
OTHER_CPF = "11144477735"


@pytest.fixture
def signer_material(tmp_path, monkeypatch):
    """Two TEST identities, each issued by its own throw-away TEST CA."""
    monkeypatch.setenv(PASS_ENV, PASSWORD)
    out = {}
    for label, name, cpf in (("maria", "Maria Alves Souza", CPF), ("outra", "Pessoa Diferente", None)):
        pfx, ca = tmp_path / f"{label}.pfx", tmp_path / f"{label}-ac.pem"
        generate_participant_test_cert(pfx, PASS_ENV, common_name=name, cpf=cpf, out_ca_pem=ca)
        out[label] = {"pfx": pfx, "ca": ca, "signer": _signer_from(load_participant_pkcs12(pfx, PASSWORD))}
    return out


def _base(tmp_path: Path, name: str = "base.pdf", text: str = "Contrato - revisao esperada") -> Path:
    return make_pdf(tmp_path / name, [{"text": text}, {"text": "Pagina de evidencias"}])


def _portal_sign(src: Path, dst: Path, signer, field: str = "GovBr_Assinatura", certify: int | None = None,
                 alter_content: bool = False, visible: bool = False) -> Path:
    """What a portal is expected to do: incremental update + one PAdES signature."""
    with src.open("rb") as inf:
        writer = IncrementalPdfFileWriter(inf, strict=False)
        if alter_content:
            stream = generic.StreamObject(stream_data=b"BT /F1 24 Tf 72 400 Td (VALOR ALTERADO) Tj ET")
            writer.add_stream_to_page(0, writer.add_object(stream))
        meta = signers.PdfSignatureMetadata(
            field_name=field,
            md_algorithm="sha256",
            subfilter=fields.SigSeedSubFilter.PADES,
            certify=certify is not None,
            docmdp_permissions=MDPPerm(certify) if certify is not None else MDPPerm.FILL_FORMS,
        )
        spec = fields.SigFieldSpec(sig_field_name=field, box=(72, 72, 272, 122) if visible else None)
        with dst.open("wb") as outf:
            signers.PdfSigner(meta, signer, new_field_spec=spec).sign_pdf(writer, output=outf)
    return dst


def _append_update(src: Path, dst: Path) -> Path:
    """An incremental update AFTER the signature (no signature in it)."""
    with src.open("rb") as inf:
        writer = IncrementalPdfFileWriter(inf, strict=False)
        writer.root["/AVDepois"] = generic.NameObject("/Alterado")
        writer.update_root()
        with dst.open("wb") as outf:
            writer.write(outf)
    return dst


def _rewrite(src: Path, dst: Path) -> Path:
    """A tool that REWRITES the whole file instead of appending (same content, other bytes)."""
    reader = PdfReader(str(src))
    writer = PdfWriter(clone_from=reader)
    with dst.open("wb") as outf:
        writer.write(outf)
    reader.stream.close()
    return dst


def test_correct_return_is_accepted_without_trust_roots(tmp_path, signer_material):
    base = _base(tmp_path)
    returned = _portal_sign(base, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"])

    result = verify_incremental(base, returned)

    assert result["accepted"] is True, result["problems"]
    assert result["problems"] == []
    assert result["prefix_preserved"] is True
    assert result["new_revisions"] == 1 and result["new_signature_count"] == 1
    sig = result["signature"]
    assert sig["intact"] and sig["valid"] and sig["coverage"] == "ENTIRE_FILE"
    # No roots: never "trusted".
    assert sig["trusted"] is False and sig["trust_reason"] == "no_trust_roots_configured"
    assert sig["test_certificate"] is True
    assert result["changes_since_base"]["modification_level"] in ("NONE", "FORM_FILLING")
    assert result["base"]["sha256"] and result["returned"]["size"] > result["base"]["size"]


def test_trusted_only_with_the_pinned_root_and_refused_with_another_chain(tmp_path, signer_material):
    base = _base(tmp_path)
    returned = _portal_sign(base, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"])

    trusted = verify_incremental(base, returned, trust_paths=[signer_material["maria"]["ca"]])
    other_chain = verify_incremental(base, returned, trust_paths=[signer_material["outra"]["ca"]])

    assert trusted["accepted"] is True and trusted["signature"]["trusted"] is True
    assert other_chain["accepted"] is False and other_chain["problems"] == ["chain_not_trusted"]


def test_a_file_that_does_not_start_with_the_expected_revision_is_refused(tmp_path, signer_material):
    base = _base(tmp_path)
    other = _base(tmp_path, "outro.pdf", "Contrato - OUTRO documento")
    returned = _portal_sign(other, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"])

    result = verify_incremental(base, returned)

    assert result["accepted"] is False and result["problems"] == ["base_not_prefix"]
    assert result["signature"] is None


def test_a_rewritten_file_is_refused_even_with_the_same_content(tmp_path, signer_material):
    base = _base(tmp_path)
    rewritten = _rewrite(base, tmp_path / "regravado.pdf")
    returned = _portal_sign(rewritten, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"])

    result = verify_incremental(base, returned)

    assert result["problems"] == ["base_not_prefix"]


def test_two_new_signatures_are_refused(tmp_path, signer_material):
    base = _base(tmp_path)
    first = _portal_sign(base, tmp_path / "um.pdf", signer_material["maria"]["signer"], field="GovBr_1")
    returned = _portal_sign(first, tmp_path / "dois.pdf", signer_material["outra"]["signer"], field="GovBr_2")

    result = verify_incremental(base, returned)

    assert result["accepted"] is False
    assert "multiple_new_signatures" in result["problems"]
    assert result["new_signature_count"] == 2


def test_a_signature_that_does_not_cover_the_whole_file_is_refused(tmp_path, signer_material):
    base = _base(tmp_path)
    signed = _portal_sign(base, tmp_path / "assinado.pdf", signer_material["maria"]["signer"])
    returned = _append_update(signed, tmp_path / "devolvido.pdf")

    result = verify_incremental(base, returned)

    assert result["accepted"] is False
    assert "signature_not_covering_file" in result["problems"]
    assert "unexpected_revision_count" in result["problems"]
    assert result["signature"]["coverage"] != "ENTIRE_FILE"


def test_content_changed_in_the_signing_revision_is_refused(tmp_path, signer_material):
    base = _base(tmp_path)
    returned = _portal_sign(base, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"], alter_content=True)

    result = verify_incremental(base, returned)

    # The signature itself is perfect (it covers what it signed); what it signed is not only
    # a signature: the page content changed after the revision we handed out.
    assert result["signature"]["intact"] and result["signature"]["valid"]
    assert result["accepted"] is False and "unpermitted_changes" in result["problems"]


def test_an_update_without_signature_is_refused(tmp_path, signer_material):
    base = _base(tmp_path)
    returned = _append_update(base, tmp_path / "devolvido.pdf")

    result = verify_incremental(base, returned)

    assert result["accepted"] is False and "no_new_signature" in result["problems"]


def test_certification_that_forbids_later_signatures_is_refused_and_p2_is_accepted(tmp_path, signer_material):
    base = _base(tmp_path)
    locked = _portal_sign(base, tmp_path / "p1.pdf", signer_material["maria"]["signer"], certify=1)
    open_for_signing = _portal_sign(base, tmp_path / "p2.pdf", signer_material["maria"]["signer"], certify=2)

    refused = verify_incremental(base, locked)
    accepted = verify_incremental(base, open_for_signing)

    assert refused["problems"] == ["docmdp_locks_document"] and refused["signature"]["docmdp_permission"] == 1
    assert accepted["accepted"] is True and accepted["signature"]["is_certification"] is True
    assert accepted["signature"]["docmdp_permission"] == 2


def test_a_visible_stamp_like_the_portal_one_is_accepted(tmp_path, signer_material):
    base = _base(tmp_path)
    returned = _portal_sign(base, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"], visible=True)

    result = verify_incremental(base, returned)

    assert result["accepted"] is True, result["problems"]


def test_cpf_is_compared_inside_and_only_the_masked_cpf_leaves(tmp_path, signer_material, monkeypatch, run_cli):
    base = _base(tmp_path)
    returned = _portal_sign(base, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"])

    monkeypatch.setenv("AV_TEST_EXPECTED_CPF", CPF)
    code, match = run_cli("verify-incremental", "--base", base, "--in", returned, "--expect-cpf-env", "AV_TEST_EXPECTED_CPF")
    monkeypatch.setenv("AV_TEST_EXPECTED_CPF", OTHER_CPF)
    _code, mismatch = run_cli("verify-incremental", "--base", base, "--in", returned, "--expect-cpf-env", "AV_TEST_EXPECTED_CPF")

    assert code == 0 and match["accepted"] is True
    assert match["signature"]["holder"]["cpf_match"] == "match"
    assert match["signature"]["holder"]["cpf_masked"] == "***.982.247-**"
    assert mismatch["accepted"] is False and mismatch["problems"] == ["holder_mismatch"]
    # The full CPF (certificate's or participant's) never goes to stdout.
    assert CPF not in str(match) and OTHER_CPF not in str(mismatch) and CPF not in str(mismatch)


def test_previous_participant_signature_is_preserved_and_checked(tmp_path, signer_material):
    base_unsigned = _base(tmp_path)
    base = tmp_path / "base-com-a1.pdf"
    participant_sign(base_unsigned, base, signer_material["outra"]["pfx"], PASS_ENV, "AV_Participante_1")
    returned = _portal_sign(base, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"])

    result = verify_incremental(base, returned)

    assert result["accepted"] is True, result["problems"]
    assert result["base"]["signature_count"] == 1 and result["returned"]["signature_count"] == 2
    assert result["previous_signatures_ok"] is True


def _raw_pdf(path: Path) -> Path:
    """Hand-written PDF whose numbers carry trailing zeros ("595.280"), like dompdf output."""
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0.000 0.000 595.280 841.890] /Contents 4 0 R /Resources << >> >>",
        b"<< /Length 18 >>\nstream\n0.000 0.000 m S\n\nendstream",
    ]
    out = bytearray(b"%PDF-1.7\n")
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(out))
        out += b"%d 0 obj\n" % number + body + b"\nendobj\n"
    xref = len(out)
    out += b"xref\n0 %d\n0000000000 65535 f \n" % (len(objects) + 1)
    for offset in offsets:
        out += b"%010d 00000 n \n" % offset
    out += b"trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (len(objects) + 1, xref)
    path.write_bytes(bytes(out))
    return path


def test_a_page_rewritten_with_the_same_numbers_is_not_a_change(tmp_path, signer_material):
    base = _raw_pdf(tmp_path / "base-zeros.pdf")
    returned = _portal_sign(base, tmp_path / "devolvido.pdf", signer_material["maria"]["signer"])

    result = verify_incremental(base, returned)

    assert result["accepted"] is True, (result["problems"], result["changes_since_base"])


def test_unreadable_input_is_an_input_error(tmp_path, run_cli):
    base = _base(tmp_path)
    corrupt = tmp_path / "corrompido.pdf"
    corrupt.write_bytes(base.read_bytes() + b"\n%%lixo sem xref\n" + b"\x00" * 64)

    with pytest.raises(InputRejected) as missing:
        verify_incremental(base, tmp_path / "nao-existe.pdf")
    code, payload = run_cli("verify-incremental", "--base", base, "--in", tmp_path / "nao-existe.pdf")

    assert missing.value.code == "missing_input"
    assert code == 4 and payload["error"]["code"] == "missing_input"
