/**
 * Chave de idempotência de um pedido financeiro (estorno). Uma por abertura do
 * formulário: repetir o envio (duplo clique, reenvio) manda a MESMA chave, e o
 * servidor devolve o mesmo pedido em vez de criar outro (Fase 2, onda D).
 */
export function newRequestKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    // Fallback RFC 4122 v4 para contextos sem `crypto.randomUUID`.
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = (Math.random() * 16) | 0;
        const value = char === 'x' ? random : (random & 0x3) | 0x8;

        return value.toString(16);
    });
}

/**
 * "49,90" / "49.90" / "1.049,90" → 4990 (centavos inteiros). `null` quando o
 * texto não é um valor positivo.
 */
export function parseAmountToCents(input: string): number | null {
    const normalized = input.trim().replace(/\s|R\$/g, '');

    if (normalized === '') {
        return null;
    }

    const decimal = normalized.includes(',')
        ? normalized.replace(/\./g, '').replace(',', '.')
        : normalized;
    const value = Number(decimal);

    if (!Number.isFinite(value) || value <= 0) {
        return null;
    }

    return Math.round(value * 100);
}
