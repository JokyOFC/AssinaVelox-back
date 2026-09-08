from pypdf import PdfReader

from conftest import make_pdf


def test_append_keeps_pages_and_boxes(tmp_path, run_cli):
    base = make_pdf(tmp_path / "base.pdf", [{}, {}])
    extra = make_pdf(tmp_path / "extra.pdf", [{"width": 500, "height": 700, "rotate": 90, "cropbox": [20, 20, 400, 600]}])
    out = tmp_path / "out.pdf"
    code, res = run_cli("append", "--base", base, "--extra", extra, "--out", out)
    assert code == 0, res
    assert res == {"ok": True, "page_count": 3}

    reader = PdfReader(str(out))
    assert len(reader.pages) == 3
    third = reader.pages[2]
    assert third.rotation == 90
    assert [float(v) for v in third.cropbox] == [20, 20, 400, 600]
    assert [float(v) for v in third.mediabox] == [0, 0, 500, 700]
    assert "Pagina de teste 1" in reader.pages[0].extract_text()
    reader.stream.close()

    code, info = run_cli("inspect", "--in", out)
    assert code == 0
    assert info["pages"][2]["width_pt"] == 580 and info["pages"][2]["height_pt"] == 380


def test_append_corrupt_extra_exit_4(tmp_path, run_cli):
    base = make_pdf(tmp_path / "base.pdf", [{}])
    bad = tmp_path / "bad.pdf"
    bad.write_bytes(b"not a pdf")
    code, res = run_cli("append", "--base", base, "--extra", bad, "--out", tmp_path / "out.pdf")
    assert code == 4 and res["error"]["code"] == "invalid_pdf"


def test_append_same_path_rejected(tmp_path, run_cli):
    base = make_pdf(tmp_path / "base.pdf", [{}])
    extra = make_pdf(tmp_path / "extra.pdf", [{}])
    code, res = run_cli("append", "--base", base, "--extra", extra, "--out", base)
    assert code == 2 and res["error"]["code"] == "same_path"
