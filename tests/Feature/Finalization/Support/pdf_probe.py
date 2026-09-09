"""Sonda de PDF para os testes de finalizacao (B-FINAL).

Nao faz parte da aplicacao nem do contrato do `pdftool`: e uma ferramenta de TESTE,
executada com o interpretador do venv em tools/pdftool (que ja tem o pypdf instalado).
Existe porque as assercoes que importam sao geometricas — "o valor autorizado caiu DENTRO
do retangulo do campo, na pagina certa, em pe" — e isso nao se verifica com uma busca de
substring no PDF.

Subcomandos:

  text   --in <pdf>                          descreve cada pagina (boxes, /Rotate) e cada
                                             trecho de texto com a posicao no espaco do
                                             usuario do PDF, mais a direcao da linha de base
  rotate --in <pdf> --out <pdf> --page N --degrees D
                                             copia o PDF aplicando /Rotate a uma pagina
                                             (fixture de pagina rotacionada)
  tamper --in <pdf> --out <pdf>              copia os bytes trocando UM byte do meio do
                                             arquivo (para provar que o sha256 muda)

Saida: exatamente um objeto JSON em stdout.
"""

import argparse
import json
import math
import sys

from pypdf import PdfReader, PdfWriter


def _page_meta(page):
    media = [float(v) for v in page.mediabox]
    crop = [float(v) for v in page.cropbox]
    return {
        "rotation": int(page.rotation or 0) % 360,
        "mediabox": media,
        "cropbox": crop,
    }


def _text_runs(page):
    found = []

    def visitor(text, cm, tm, _font_dict, font_size):
        if not text or not text.strip():
            return
        a = tm[0] * cm[0] + tm[1] * cm[2]
        b = tm[0] * cm[1] + tm[1] * cm[3]
        x = tm[4] * cm[0] + tm[5] * cm[2] + cm[4]
        y = tm[4] * cm[1] + tm[5] * cm[3] + cm[5]
        norm = math.hypot(a, b) or 1.0
        found.append(
            {
                "text": text.strip(),
                "x": x,
                "y": y,
                "dx": a / norm,
                "dy": b / norm,
                "font_size": font_size,
            }
        )

    page.extract_text(visitor_text=visitor)
    return found


def cmd_text(args):
    reader = PdfReader(args.input)
    pages = []
    for index, page in enumerate(reader.pages, start=1):
        meta = _page_meta(page)
        meta["index"] = index
        meta["runs"] = _text_runs(page)
        meta["text"] = page.extract_text() or ""
        pages.append(meta)
    result = {"ok": True, "page_count": len(reader.pages), "pages": pages}
    reader.stream.close()
    return result


def cmd_rotate(args):
    reader = PdfReader(args.input)
    writer = PdfWriter()
    for index, page in enumerate(reader.pages, start=1):
        if index == args.page:
            page.rotation = args.degrees
        writer.add_page(page)
    with open(args.output, "wb") as handle:
        writer.write(handle)
    reader.stream.close()
    return {"ok": True, "page_count": len(writer.pages), "rotated_page": args.page}


def cmd_tamper(args):
    with open(args.input, "rb") as handle:
        data = bytearray(handle.read())
    if len(data) < 64:
        raise ValueError("arquivo pequeno demais para adulterar")
    offset = len(data) // 2
    data[offset] = (data[offset] + 1) % 256
    with open(args.output, "wb") as handle:
        handle.write(bytes(data))
    return {"ok": True, "offset": offset, "size": len(data)}


def main(argv):
    parser = argparse.ArgumentParser(prog="pdf_probe")
    sub = parser.add_subparsers(dest="command", required=True)

    p_text = sub.add_parser("text")
    p_text.add_argument("--in", dest="input", required=True)
    p_text.set_defaults(handler=cmd_text)

    p_rotate = sub.add_parser("rotate")
    p_rotate.add_argument("--in", dest="input", required=True)
    p_rotate.add_argument("--out", dest="output", required=True)
    p_rotate.add_argument("--page", type=int, required=True)
    p_rotate.add_argument("--degrees", type=int, default=90)
    p_rotate.set_defaults(handler=cmd_rotate)

    p_tamper = sub.add_parser("tamper")
    p_tamper.add_argument("--in", dest="input", required=True)
    p_tamper.add_argument("--out", dest="output", required=True)
    p_tamper.set_defaults(handler=cmd_tamper)

    args = parser.parse_args(argv)

    try:
        result = args.handler(args)
    except Exception as exc:  # noqa: BLE001 - contrato de saida do probe
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False))
        return 1

    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
