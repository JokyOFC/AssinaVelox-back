"""Test fixture generator for tests/Feature/Phase3/Anchors (F-ANCHOR).

Usage: python make_anchor_pdf.py <spec.json> <out.pdf>

spec: {"pages": [{"texts": [[x, y, "text", size?]], "rotate": 0|90|180|270,
                  "cropbox": [x0, y0, x1, y1], "box": true, "width": 600, "height": 800}],
       "encrypt_owner": "senha"}

Run with the pdftool venv interpreter (reportlab + pypdf). Nothing here is shipped.
"""

import io
import json
import sys

from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject
from reportlab.pdfgen import canvas


def main(spec_path: str, out_path: str) -> None:
    with open(spec_path, encoding="utf-8") as fh:
        spec = json.load(fh)
    pages = spec["pages"]
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=(600, 800))
    for page in pages:
        c.setPageSize((page.get("width", 600), page.get("height", 800)))
        for item in page.get("texts", []):
            x, y, text = item[0], item[1], item[2]
            c.setFont("Helvetica", item[3] if len(item) > 3 else 12)
            c.drawString(x, y, text)
        if page.get("box"):
            c.rect(100, 100, 300, 200, stroke=1, fill=1)
        c.showPage()
    c.save()

    reader = PdfReader(io.BytesIO(buf.getvalue()))
    writer = PdfWriter(clone_from=reader)
    for index, page in enumerate(pages):
        target = writer.pages[index]
        if "cropbox" in page:
            target.cropbox = RectangleObject(page["cropbox"])
        if page.get("rotate"):
            target.rotate(page["rotate"])
    if spec.get("encrypt_owner"):
        writer.encrypt(user_password="", owner_password=spec["encrypt_owner"], algorithm="AES-128")
    with open(out_path, "wb") as fh:
        writer.write(fh)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
