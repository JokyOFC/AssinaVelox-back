import pytest
from PIL import Image
from PIL.PngImagePlugin import PngInfo
from pypdf import PdfReader

from conftest import A4_H, A4_W, make_png


def _jpeg(path, size, exif=None):
    img = Image.new("RGB", size, (200, 30, 30))
    kwargs = {"quality": 85}
    if exif is not None:
        kwargs["exif"] = exif
    img.save(path, "JPEG", **kwargs)
    return path


def test_portrait_png_a4(tmp_path, run_cli):
    png = make_png(tmp_path / "p.png", size=(300, 500))
    out = tmp_path / "out.pdf"
    code, res = run_cli("image2pdf", "--in", png, "--out", out)
    assert code == 0, res
    assert res["ok"] and res["page_count"] == 1
    page = res["pages"][0]
    assert page["width_pt"] == pytest.approx(A4_W, abs=0.01)
    assert page["height_pt"] == pytest.approx(A4_H, abs=0.01)
    assert res["source"] == {"width_px": 300, "height_px": 500, "format": "PNG"}
    assert res["page_size"] == "a4"


def test_landscape_jpeg_a4(tmp_path, run_cli):
    jpg = _jpeg(tmp_path / "l.jpg", (800, 400))
    code, res = run_cli("image2pdf", "--in", jpg, "--out", tmp_path / "out.pdf", "--page", "A4")
    assert code == 0, res
    page = res["pages"][0]
    assert page["width_pt"] == pytest.approx(A4_H, abs=0.01)  # landscape
    assert page["height_pt"] == pytest.approx(A4_W, abs=0.01)
    assert res["source"]["format"] == "JPEG"


def test_letter_landscape(tmp_path, run_cli):
    jpg = _jpeg(tmp_path / "l.jpg", (800, 400))
    code, res = run_cli("image2pdf", "--in", jpg, "--out", tmp_path / "out.pdf", "--page", "letter", "--margin-pt", "18")
    assert code == 0
    page = res["pages"][0]
    assert (page["width_pt"], page["height_pt"]) == (792, 612)


def test_fit_page_equals_image_size(tmp_path, run_cli):
    png = make_png(tmp_path / "p.png", size=(300, 500))
    code, res = run_cli("image2pdf", "--in", png, "--out", tmp_path / "out.pdf", "--page", "fit")
    assert code == 0
    page = res["pages"][0]
    assert (page["width_pt"], page["height_pt"]) == (300, 500)


def test_longest_side_capped_to_4000(tmp_path, run_cli):
    png = make_png(tmp_path / "wide.png", size=(5000, 100), transparent=False)
    code, res = run_cli("image2pdf", "--in", png, "--out", tmp_path / "out.pdf", "--page", "fit")
    assert code == 0
    assert res["normalized"] == {"width_px": 4000, "height_px": 80}
    assert (res["pages"][0]["width_pt"], res["pages"][0]["height_pt"]) == (4000, 80)
    assert res["source"]["width_px"] == 5000


def test_jpeg_exif_metadata_stripped(tmp_path, run_cli):
    exif = Image.Exif()
    exif[0x010F] = "SecretMaker"  # Make
    exif[0x0110] = "SecretModel"  # Model
    exif[0x010E] = "SecretDescription"  # ImageDescription
    jpg = _jpeg(tmp_path / "meta.jpg", (640, 480), exif=exif.tobytes())
    assert b"SecretMaker" in jpg.read_bytes()
    out = tmp_path / "out.pdf"
    code, res = run_cli("image2pdf", "--in", jpg, "--out", out)
    assert code == 0, res
    data = out.read_bytes()
    assert b"SecretMaker" not in data and b"SecretModel" not in data and b"SecretDescription" not in data
    reader = PdfReader(str(out))
    images = reader.pages[0].images
    assert len(images) == 1
    embedded = images[0].image
    assert len(embedded.getexif()) == 0
    assert "exif" not in embedded.info
    reader.stream.close()


def test_png_text_chunks_stripped(tmp_path, run_cli):
    img = Image.new("RGB", (120, 80), (10, 200, 10))
    info = PngInfo()
    info.add_text("Comment", "TopSecretComment")
    png = tmp_path / "meta.png"
    img.save(png, "PNG", pnginfo=info)
    assert b"TopSecretComment" in png.read_bytes()
    out = tmp_path / "out.pdf"
    code, _ = run_cli("image2pdf", "--in", png, "--out", out)
    assert code == 0
    assert b"TopSecretComment" not in out.read_bytes()


def test_exif_orientation_is_applied(tmp_path, run_cli):
    exif = Image.Exif()
    exif[0x0112] = 6  # Orientation: rotate 90 CW on display
    jpg = _jpeg(tmp_path / "rot.jpg", (400, 200), exif=exif.tobytes())
    code, res = run_cli("image2pdf", "--in", jpg, "--out", tmp_path / "out.pdf")
    assert code == 0
    assert res["normalized"] == {"width_px": 200, "height_px": 400}
    assert res["pages"][0]["height_pt"] > res["pages"][0]["width_pt"]  # portrait page


def test_webp_supported(tmp_path, run_cli):
    webp = tmp_path / "w.webp"
    Image.new("RGB", (320, 240), (0, 0, 255)).save(webp, "WEBP")
    code, res = run_cli("image2pdf", "--in", webp, "--out", tmp_path / "out.pdf")
    assert code == 0 and res["source"]["format"] == "WEBP"


def test_unsupported_format_rejected(tmp_path, run_cli):
    gif = tmp_path / "x.gif"
    Image.new("P", (32, 32)).save(gif, "GIF")
    code, res = run_cli("image2pdf", "--in", gif, "--out", tmp_path / "out.pdf")
    assert code == 4 and res["error"]["code"] == "unsupported_image"


def test_invalid_image_rejected(tmp_path, run_cli):
    fake = tmp_path / "fake.png"
    fake.write_bytes(b"definitely not an image")
    code, res = run_cli("image2pdf", "--in", fake, "--out", tmp_path / "out.pdf")
    assert code == 4 and res["error"]["code"] == "invalid_image"


def test_too_many_pixels_rejected(tmp_path, run_cli):
    # 1-bit image keeps memory small while exceeding the 40 MP limit (6500*6500 = 42.25 MP).
    huge = tmp_path / "huge.png"
    Image.new("1", (6500, 6500)).save(huge, "PNG")
    code, res = run_cli("image2pdf", "--in", huge, "--out", tmp_path / "out.pdf")
    assert code == 4 and res["error"]["code"] == "image_too_large"


def test_bad_page_size_usage_error(tmp_path, run_cli):
    png = make_png(tmp_path / "p.png")
    code, res = run_cli("image2pdf", "--in", png, "--out", tmp_path / "out.pdf", "--page", "tabloid")
    assert code == 2 and res["error"]["code"] == "usage_error"
