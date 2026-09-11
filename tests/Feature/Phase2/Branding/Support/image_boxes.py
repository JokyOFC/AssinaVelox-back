"""Lista onde cada imagem é desenhada numa página de PDF (testes de C-BRAND).

Uso: python image_boxes.py arquivo.pdf [pagina]

Percorre o fluxo de conteúdo da página acompanhando a matriz de transformação (q/Q/cm),
entra em Form XObjects (aplicando /Matrix) e, a cada `Do` de uma imagem, devolve a caixa
que o quadrado unitário ocupa em coordenadas da página (pontos, origem embaixo à esquerda).

Saída: JSON {"width": W, "height": H, "images": [{"x0", "y0", "x1", "y1"}, ...]}.
Só leitura; não altera o arquivo.
"""
import json
import sys

from pypdf import PdfReader
from pypdf.generic import ContentStream


def mult(m, n):
    a, b, c, d, e, f = m
    A, B, C, D, E, F = n
    return [a * A + b * C, a * B + b * D, c * A + d * C, c * B + d * D, e * A + f * C + E, e * B + f * D + F]


def box(ctm):
    a, b, c, d, e, f = ctm
    points = [(0, 0), (1, 0), (0, 1), (1, 1)]
    xs = [a * x + c * y + e for x, y in points]
    ys = [b * x + d * y + f for x, y in points]
    return {"x0": min(xs), "y0": min(ys), "x1": max(xs), "y1": max(ys)}


def walk(content, resources, ctm, reader, out, depth=0):
    stream = ContentStream(content, reader)
    stack = []
    xobjects = resources.get("/XObject") if resources is not None else None
    xobjects = xobjects.get_object() if xobjects is not None else {}
    for operands, operator in stream.operations:
        if operator == b"q":
            stack.append(list(ctm))
        elif operator == b"Q":
            ctm = stack.pop() if stack else ctm
        elif operator == b"cm":
            ctm = mult([float(v) for v in operands], ctm)
        elif operator == b"Do":
            name = operands[0]
            if name not in xobjects:
                continue
            xobject = xobjects[name].get_object()
            subtype = xobject.get("/Subtype")
            if subtype == "/Image":
                out.append(box(ctm))
            elif subtype == "/Form" and depth < 6:
                matrix = [float(v) for v in xobject.get("/Matrix", [1, 0, 0, 1, 0, 0])]
                inner = xobject.get("/Resources")
                inner = inner.get_object() if inner is not None else resources
                walk(xobject, inner, mult(matrix, ctm), reader, out, depth + 1)


def main():
    reader = PdfReader(sys.argv[1])
    index = int(sys.argv[2]) - 1 if len(sys.argv) > 2 else 0
    page = reader.pages[index]
    resources = page.get("/Resources")
    resources = resources.get_object() if resources is not None else None
    out = []
    walk(page.get_contents(), resources, [1, 0, 0, 1, 0, 0], reader, out)
    media = page.mediabox
    print(json.dumps({"width": float(media.width), "height": float(media.height), "images": out}))


if __name__ == "__main__":
    main()
