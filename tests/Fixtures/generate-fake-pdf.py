"""Gera tests/Fixtures/fake-converted.pdf (PDF de uma pagina usado pelo FakePdfConverter).

Script one-off, executado com o venv do pdftool a partir de tools/pdftool:

    .venv/Scripts/python.exe ../../tests/Fixtures/generate-fake-pdf.py   # Windows
    .venv/bin/python ../../tests/Fixtures/generate-fake-pdf.py           # Linux

O PDF resultante e deterministico o suficiente para revisao (sem data de criacao
nem metadados variaveis) e marca visivelmente que se trata de um FAKE.
"""

from __future__ import annotations

from pathlib import Path

from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

OUT = Path(__file__).resolve().parent / "fake-converted.pdf"


def main() -> None:
    c = canvas.Canvas(str(OUT), pagesize=A4, invariant=True, pageCompression=0)
    c.setTitle("")
    c.setAuthor("")
    c.setSubject("")
    c.setCreator("AssinaVelox tests")
    width, height = A4
    c.setFont("Helvetica-Bold", 20)
    c.drawCentredString(width / 2, height - 120, "DOCUMENTO FAKE")
    c.setFont("Helvetica", 12)
    c.drawCentredString(width / 2, height - 150, "Conversao simulada pelo FakePdfConverter (dev/test).")
    c.drawCentredString(width / 2, height - 170, "Este PDF NAO e o conteudo do documento enviado.")
    c.showPage()
    c.save()
    print(OUT)


if __name__ == "__main__":
    main()
