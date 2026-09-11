/**
 * PIN do remetente (Fase 2 §2.9): segredo compartilhado que o remetente combina com o
 * participante POR FORA do AssinaVelox. É pedido depois do código e nunca o substitui.
 *
 * As regras abaixo espelham `SenderPins::isWellFormed()` e `SenderPins::isWeak()` só para
 * avisar antes de salvar; o servidor recusa de novo (422 em `recipients.N.pin`).
 */

export function pinDigits(value: string, max: number): string {
    return value.replace(/\D+/g, '').slice(0, max);
}

/** Dígitos repetidos (0000) ou sequência crescente/decrescente (1234, 98765). */
export function isWeakPin(pin: string): boolean {
    if (/^(\d)\1+$/.test(pin)) {
        return true;
    }

    const ascending = '01234567890123456789';
    const descending = '98765432109876543210';

    return ascending.includes(pin) || descending.includes(pin);
}

export function pinProblem(
    pin: string,
    min: number,
    max: number,
): string | null {
    if (!/^\d+$/.test(pin) || pin.length < min || pin.length > max) {
        return `O PIN deve ter de ${min} a ${max} dígitos.`;
    }

    if (isWeakPin(pin)) {
        return 'Escolha um PIN menos previsível (evite sequências como 1234 e dígitos repetidos como 0000).';
    }

    return null;
}

/** PIN aleatório com `crypto.getRandomValues` (nunca `Math.random`), já sem os fracos. */
export function generatePin(length: number): string {
    const size = Math.max(4, Math.min(8, length));

    for (;;) {
        const bytes = new Uint32Array(size);
        crypto.getRandomValues(bytes);

        const pin = Array.from(bytes, (value) => String(value % 10)).join('');

        if (!isWeakPin(pin)) {
            return pin;
        }
    }
}
