/**
 * Gerador de QR Code mínimo (modo byte, nível de correção M, versões 1–10).
 *
 * Por que caseiro: a página de evidências precisa do QR da URL de verificação
 * e o projeto não tem biblioteca de QR no front (bacon/bacon-qr-code é PHP e
 * roda no relatório em PDF). São ~200 linhas, sem dependência, e a matriz foi
 * conferida módulo a módulo contra a saída do bacon-qr-code para as mesmas
 * entradas (ver relatório do incremento 4).
 *
 * Escopo deliberado: versões 1–10 no nível M cobrem até 213 bytes — mais do que
 * qualquer URL de verificação (`…/verificar/XXXX-XXXX-XXXX`). Acima disso a
 * função devolve `null` e o componente simplesmente não desenha o QR.
 */

const ECC_LEVEL_M_BITS = 0b00;

/** [códigos de correção por bloco, blocos g1, dados g1, blocos g2, dados g2] */
const ECC_TABLE_M: readonly (readonly [
    number,
    number,
    number,
    number,
    number,
])[] = [
    [10, 1, 16, 0, 0], // v1
    [16, 1, 28, 0, 0], // v2
    [26, 1, 44, 0, 0], // v3
    [18, 2, 32, 0, 0], // v4
    [24, 2, 43, 0, 0], // v5
    [16, 4, 27, 0, 0], // v6
    [18, 4, 31, 0, 0], // v7
    [22, 2, 38, 2, 39], // v8
    [22, 3, 36, 2, 37], // v9
    [26, 4, 43, 1, 44], // v10
];

/** Centros dos padrões de alinhamento por versão (1 = nenhum). */
const ALIGNMENT_CENTERS: readonly (readonly number[])[] = [
    [],
    [6, 18],
    [6, 22],
    [6, 26],
    [6, 30],
    [6, 34],
    [6, 22, 38],
    [6, 24, 42],
    [6, 26, 46],
    [6, 28, 50],
];

// --- GF(256) -----------------------------------------------------------------

const EXP = new Uint8Array(512);
const LOG = new Uint8Array(256);

(() => {
    let x = 1;

    for (let i = 0; i < 255; i += 1) {
        EXP[i] = x;
        LOG[x] = i;
        x <<= 1;

        if (x & 0x100) {
            x ^= 0x11d;
        }
    }

    for (let i = 255; i < 512; i += 1) {
        EXP[i] = EXP[i - 255];
    }
})();

function gfMul(a: number, b: number): number {
    if (a === 0 || b === 0) {
        return 0;
    }

    return EXP[LOG[a] + LOG[b]];
}

/** Polinômio gerador de Reed–Solomon de grau `degree`. */
function rsGenerator(degree: number): Uint8Array {
    let poly = new Uint8Array([1]);

    for (let i = 0; i < degree; i += 1) {
        const next = new Uint8Array(poly.length + 1);

        for (let j = 0; j < poly.length; j += 1) {
            next[j] ^= poly[j];
            next[j + 1] ^= gfMul(poly[j], EXP[i]);
        }

        poly = next;
    }

    return poly;
}

function rsEncode(data: Uint8Array, ecLength: number): Uint8Array {
    const generator = rsGenerator(ecLength);
    const result = new Uint8Array(ecLength);

    for (let i = 0; i < data.length; i += 1) {
        const factor = data[i] ^ result[0];

        result.copyWithin(0, 1);
        result[ecLength - 1] = 0;

        if (factor !== 0) {
            for (let j = 0; j < ecLength; j += 1) {
                result[j] ^= gfMul(generator[j + 1], factor);
            }
        }
    }

    return result;
}

// --- BCH (formato e versão) --------------------------------------------------

function bch(value: number, poly: number, polyBits: number): number {
    let rest = value;

    while (bitLength(rest) - polyBits >= 0) {
        rest ^= poly << (bitLength(rest) - polyBits);
    }

    return rest;
}

function bitLength(value: number): number {
    let length = 0;

    while (value >>> length) {
        length += 1;
    }

    return length;
}

function formatBits(maskPattern: number): number {
    const data = (ECC_LEVEL_M_BITS << 3) | maskPattern;

    return ((data << 10) | bch(data << 10, 0x537, 11)) ^ 0x5412;
}

function versionBits(version: number): number {
    return (version << 12) | bch(version << 12, 0x1f25, 13);
}

// --- Bit buffer --------------------------------------------------------------

class BitBuffer {
    private readonly bits: number[] = [];

    put(value: number, length: number): void {
        for (let i = length - 1; i >= 0; i -= 1) {
            this.bits.push((value >>> i) & 1);
        }
    }

    get length(): number {
        return this.bits.length;
    }

    toBytes(): Uint8Array {
        const bytes = new Uint8Array(Math.ceil(this.bits.length / 8));

        for (let i = 0; i < this.bits.length; i += 1) {
            if (this.bits[i]) {
                bytes[i >>> 3] |= 0x80 >>> (i % 8);
            }
        }

        return bytes;
    }
}

// --- Matriz ------------------------------------------------------------------

const RESERVED = 2; // marcador de função; nunca recebe dados nem máscara

function placeFunctionPatterns(matrix: Uint8Array[], version: number): void {
    const size = matrix.length;

    const setFn = (row: number, col: number, dark: boolean) => {
        matrix[row][col] = dark ? RESERVED | 1 : RESERVED;
    };

    // Localizadores + separadores.
    for (const [baseRow, baseCol] of [
        [0, 0],
        [0, size - 7],
        [size - 7, 0],
    ]) {
        for (let r = -1; r <= 7; r += 1) {
            for (let c = -1; c <= 7; c += 1) {
                const row = baseRow + r;
                const col = baseCol + c;

                if (row < 0 || row >= size || col < 0 || col >= size) {
                    continue;
                }

                const inRing =
                    (r >= 0 && r <= 6 && (c === 0 || c === 6)) ||
                    (c >= 0 && c <= 6 && (r === 0 || r === 6));
                const inCore = r >= 2 && r <= 4 && c >= 2 && c <= 4;

                setFn(row, col, inRing || inCore);
            }
        }
    }

    // Temporizadores.
    for (let i = 8; i < size - 8; i += 1) {
        setFn(6, i, i % 2 === 0);
        setFn(i, 6, i % 2 === 0);
    }

    // Alinhamento.
    const centers = ALIGNMENT_CENTERS[version - 1];

    for (const row of centers) {
        for (const col of centers) {
            const nearFinder =
                (row <= 8 && col <= 8) ||
                (row <= 8 && col >= size - 9) ||
                (row >= size - 9 && col <= 8);

            if (nearFinder) {
                continue;
            }

            for (let r = -2; r <= 2; r += 1) {
                for (let c = -2; c <= 2; c += 1) {
                    const ring = Math.max(Math.abs(r), Math.abs(c));

                    setFn(row + r, col + c, ring !== 1);
                }
            }
        }
    }

    // Módulo escuro fixo.
    setFn(size - 8, 8, true);

    // Áreas reservadas do formato.
    for (let i = 0; i <= 8; i += 1) {
        if (i !== 6) {
            setFn(8, i, false);
            setFn(i, 8, false);
        }
    }

    for (let i = 0; i < 8; i += 1) {
        setFn(8, size - 1 - i, false);
        setFn(size - 1 - i, 8, false);
    }

    // Informação de versão (v ≥ 7).
    if (version >= 7) {
        const bits = versionBits(version);

        for (let i = 0; i < 18; i += 1) {
            const dark = ((bits >>> i) & 1) === 1;
            const a = Math.floor(i / 3);
            const b = (i % 3) + size - 11;

            setFn(b, a, dark);
            setFn(a, b, dark);
        }
    }
}

function placeData(matrix: Uint8Array[], data: Uint8Array): void {
    const size = matrix.length;
    let bitIndex = 0;
    let upward = true;
    let right = size - 1;

    while (right > 0) {
        if (right === 6) {
            right = 5; // a coluna 6 é o temporizador vertical
        }

        for (let step = 0; step < size; step += 1) {
            const row = upward ? size - 1 - step : step;

            for (const col of [right, right - 1]) {
                if (matrix[row][col] & RESERVED) {
                    continue;
                }

                // Módulos além do fluxo de dados ficam claros (bits restantes).
                const bit =
                    bitIndex < data.length * 8
                        ? (data[bitIndex >>> 3] >>> (7 - (bitIndex % 8))) & 1
                        : 0;

                matrix[row][col] = bit;
                bitIndex += 1;
            }
        }

        upward = !upward;
        right -= 2;
    }
}

function maskAt(pattern: number, row: number, col: number): boolean {
    switch (pattern) {
        case 0:
            return (row + col) % 2 === 0;
        case 1:
            return row % 2 === 0;
        case 2:
            return col % 3 === 0;
        case 3:
            return (row + col) % 3 === 0;
        case 4:
            return (Math.floor(row / 2) + Math.floor(col / 3)) % 2 === 0;
        case 5:
            return ((row * col) % 2) + ((row * col) % 3) === 0;
        case 6:
            return (((row * col) % 2) + ((row * col) % 3)) % 2 === 0;
        default:
            return (((row + col) % 2) + ((row * col) % 3)) % 2 === 0;
    }
}

function applyMaskAndFormat(base: Uint8Array[], pattern: number): boolean[][] {
    const size = base.length;
    const out: boolean[][] = Array.from({ length: size }, (_, row) =>
        Array.from({ length: size }, (_, col) => {
            const cell = base[row][col];
            const dark = (cell & 1) === 1;

            if (cell & RESERVED) {
                return dark;
            }

            return maskAt(pattern, row, col) ? !dark : dark;
        }),
    );

    const bits = formatBits(pattern);

    for (let i = 0; i < 15; i += 1) {
        const dark = ((bits >>> i) & 1) === 1;

        // Cópia vertical (coluna 8, de cima para baixo e no canto inferior).
        if (i < 6) {
            out[i][8] = dark;
        } else if (i < 8) {
            out[i + 1][8] = dark;
        } else {
            out[size - 15 + i][8] = dark;
        }

        // Cópia horizontal (linha 8, da direita para a esquerda).
        if (i < 8) {
            out[8][size - 1 - i] = dark;
        } else if (i === 8) {
            out[8][7] = dark;
        } else {
            out[8][14 - i] = dark;
        }
    }

    out[size - 8][8] = true; // módulo escuro

    return out;
}

function penalty(matrix: boolean[][]): number {
    const size = matrix.length;
    let score = 0;

    // Regra 1: sequências de 5+ na mesma cor.
    for (let i = 0; i < size; i += 1) {
        for (const horizontal of [true, false]) {
            let run = 1;

            for (let j = 1; j < size; j += 1) {
                const prev = horizontal ? matrix[i][j - 1] : matrix[j - 1][i];
                const curr = horizontal ? matrix[i][j] : matrix[j][i];

                if (prev === curr) {
                    run += 1;
                } else {
                    if (run >= 5) {
                        score += run - 2;
                    }

                    run = 1;
                }
            }

            if (run >= 5) {
                score += run - 2;
            }
        }
    }

    // Regra 2: blocos 2×2 da mesma cor.
    for (let row = 0; row < size - 1; row += 1) {
        for (let col = 0; col < size - 1; col += 1) {
            const v = matrix[row][col];

            if (
                v === matrix[row][col + 1] &&
                v === matrix[row + 1][col] &&
                v === matrix[row + 1][col + 1]
            ) {
                score += 3;
            }
        }
    }

    // Regra 3: padrão 1:1:3:1:1 com quatro claros de um dos lados.
    const pattern = [true, false, true, true, true, false, true];
    const light4 = [false, false, false, false];
    const matches = (cells: boolean[], at: number, seq: boolean[]) =>
        seq.every((value, index) => cells[at + index] === value);

    for (let i = 0; i < size; i += 1) {
        for (const horizontal of [true, false]) {
            const line: boolean[] = [];

            for (let j = 0; j < size; j += 1) {
                line.push(horizontal ? matrix[i][j] : matrix[j][i]);
            }

            for (let j = 0; j + 7 <= size; j += 1) {
                if (!matches(line, j, pattern)) {
                    continue;
                }

                const before = j >= 4 && matches(line, j - 4, light4);
                const after = j + 11 <= size && matches(line, j + 7, light4);

                if (before || after) {
                    score += 40;
                }
            }
        }
    }

    // Regra 4: desvio da proporção de módulos escuros.
    let dark = 0;

    for (let row = 0; row < size; row += 1) {
        for (let col = 0; col < size; col += 1) {
            if (matrix[row][col]) {
                dark += 1;
            }
        }
    }

    const percent = (dark * 100) / (size * size);
    score += Math.floor(Math.abs(percent - 50) / 5) * 10;

    return score;
}

// --- API ---------------------------------------------------------------------

export interface QrMatrix {
    version: number;
    size: number;
    /** `true` = módulo escuro. */
    modules: boolean[][];
}

/**
 * Gera a matriz do QR (nível M). Devolve `null` quando o conteúdo não cabe nas
 * versões suportadas ou tem caracteres fora de latin-1 que o `TextEncoder`
 * expandiria além do limite — o chamador simplesmente não desenha o QR.
 */
export function qrMatrix(content: string): QrMatrix | null {
    const data = new TextEncoder().encode(content);

    let version = 0;

    for (let candidate = 1; candidate <= ECC_TABLE_M.length; candidate += 1) {
        const [, blocks1, data1, blocks2, data2] = ECC_TABLE_M[candidate - 1];
        const totalData = blocks1 * data1 + blocks2 * data2;
        const cci = candidate <= 9 ? 8 : 16;

        if (4 + cci + data.length * 8 <= totalData * 8) {
            version = candidate;
            break;
        }
    }

    if (version === 0) {
        return null;
    }

    const [ecPerBlock, blocks1, data1, blocks2, data2] =
        ECC_TABLE_M[version - 1];
    const totalData = blocks1 * data1 + blocks2 * data2;
    const cci = version <= 9 ? 8 : 16;

    const buffer = new BitBuffer();

    buffer.put(0b0100, 4);
    buffer.put(data.length, cci);

    for (const byte of data) {
        buffer.put(byte, 8);
    }

    const capacityBits = totalData * 8;

    buffer.put(0, Math.min(4, capacityBits - buffer.length));

    if (buffer.length % 8 !== 0) {
        buffer.put(0, 8 - (buffer.length % 8));
    }

    const bytes = Array.from(buffer.toBytes());

    for (let i = 0; bytes.length < totalData; i += 1) {
        bytes.push(i % 2 === 0 ? 0xec : 0x11);
    }

    // Blocos de dados + correção.
    const dataBlocks: Uint8Array[] = [];
    const eccBlocks: Uint8Array[] = [];
    let cursor = 0;

    for (const [count, size] of [
        [blocks1, data1],
        [blocks2, data2],
    ]) {
        for (let i = 0; i < count; i += 1) {
            const block = Uint8Array.from(bytes.slice(cursor, cursor + size));

            cursor += size;
            dataBlocks.push(block);
            eccBlocks.push(rsEncode(block, ecPerBlock));
        }
    }

    // Intercalação.
    const interleaved: number[] = [];
    const maxData = Math.max(data1, data2);

    for (let i = 0; i < maxData; i += 1) {
        for (const block of dataBlocks) {
            if (i < block.length) {
                interleaved.push(block[i]);
            }
        }
    }

    for (let i = 0; i < ecPerBlock; i += 1) {
        for (const block of eccBlocks) {
            interleaved.push(block[i]);
        }
    }

    // Os bits restantes da versão ficam claros: `placeData` preenche com zero
    // tudo o que passa do fluxo de dados.
    const payload = Uint8Array.from(interleaved);
    const size = 17 + version * 4;
    const base: Uint8Array[] = Array.from(
        { length: size },
        () => new Uint8Array(size),
    );

    placeFunctionPatterns(base, version);
    placeData(base, payload);

    let best: boolean[][] | null = null;
    let bestScore = Number.POSITIVE_INFINITY;

    for (let pattern = 0; pattern < 8; pattern += 1) {
        const candidate = applyMaskAndFormat(base, pattern);
        const score = penalty(candidate);

        if (score < bestScore) {
            bestScore = score;
            best = candidate;
        }
    }

    return best ? { version, size, modules: best } : null;
}

/**
 * Caminho SVG único com todos os módulos escuros. `quietZone` em módulos
 * (a norma pede 4).
 */
export function qrSvgPath(matrix: QrMatrix, quietZone = 4): string {
    const parts: string[] = [];

    for (let row = 0; row < matrix.size; row += 1) {
        for (let col = 0; col < matrix.size; col += 1) {
            if (matrix.modules[row][col]) {
                parts.push(`M${col + quietZone} ${row + quietZone}h1v1h-1z`);
            }
        }
    }

    return parts.join('');
}
