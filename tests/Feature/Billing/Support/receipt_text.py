"""Extrai o texto de um PDF para os testes de cobrança.

Roda no venv do pdftool (tools/pdftool/.venv) e usa só pypdf — nenhuma dependência
nova. Existe porque o DOMPDF embute a DejaVu Sans com `Identity-H`: o texto do PDF
fica em identificadores de glifo, não em ASCII, e uma leitura ingênua do arquivo não
encontraria o aviso "não é documento fiscal" mesmo estando lá impresso. O pypdf usa o
mapa ToUnicode do próprio PDF e devolve o texto como o leitor humano o vê.

Uso: python receipt_text.py <caminho-do-pdf>
Saída: o texto de todas as páginas em UTF-8, uma página por bloco.
"""

from __future__ import annotations

import sys

from pypdf import PdfReader


def main() -> int:
    if len(sys.argv) != 2:
        print("uso: receipt_text.py <caminho-do-pdf>", file=sys.stderr)
        return 2

    reader = PdfReader(sys.argv[1])
    text = "\n".join(page.extract_text() or "" for page in reader.pages)

    sys.stdout.buffer.write(text.encode("utf-8"))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
