/**
 * Celular do participante (Fase 2 §2.9). Máscara e pré-validação no cliente são conforto:
 * quem decide é o servidor (`App\Rules\PhoneE164`, libphonenumber, região BR, só celular).
 *
 * Formato exibido: "+55 11 91234-5678" (o exemplo do contrato). Números de outro país
 * (começando por "+" e outro código) passam sem máscara e com checagem só de tamanho E.164.
 */

function digitsOf(value: string): string {
    return value.replace(/\D+/g, '');
}

/** Parte nacional brasileira (DDD + número), sem o 55, até 11 dígitos. */
function brazilianNational(value: string): string {
    const trimmed = value.trim();
    let digits = digitsOf(trimmed);

    if (trimmed.startsWith('+')) {
        digits = digits.startsWith('55') ? digits.slice(2) : digits;
    } else if (digits.length > 11 && digits.startsWith('55')) {
        digits = digits.slice(2);
    }

    // "0 11 9…" (discagem com zero) → sem o zero.
    if (digits.length > 11 && digits.startsWith('0')) {
        digits = digits.slice(1);
    }

    return digits.slice(0, 11);
}

function isForeign(value: string): boolean {
    const trimmed = value.trim();

    return (
        trimmed.startsWith('+') &&
        digitsOf(trimmed).length >= 2 &&
        !digitsOf(trimmed).startsWith('55')
    );
}

/** Máscara progressiva enquanto a pessoa digita. */
export function formatPhoneInput(value: string): string {
    if (value.trim() === '') {
        return '';
    }

    if (isForeign(value)) {
        return `+${digitsOf(value).slice(0, 15)}`;
    }

    if (value.trim() === '+') {
        return '+';
    }

    const national = brazilianNational(value);
    const ddd = national.slice(0, 2);
    const rest = national.slice(2);

    if (national.length === 0) {
        return value.trim().startsWith('+') ? '+55' : '';
    }

    if (rest.length === 0) {
        return `+55 ${ddd}`;
    }

    const head = rest.length > 5 ? rest.slice(0, rest.length - 4) : rest;
    const tail = rest.length > 5 ? rest.slice(rest.length - 4) : '';

    return `+55 ${ddd} ${head}${tail ? `-${tail}` : ''}`;
}

/** "+5511912345678" (como o servidor grava) → "+55 11 91234-5678". */
export function formatStoredPhone(value: string | null | undefined): string {
    return value ? formatPhoneInput(value) : '';
}

export type PhoneCheck = 'empty' | 'incomplete' | 'invalid' | 'ok';

/**
 * Pré-validação: celular brasileiro tem DDD válido (11–99, sem zero) e 9 dígitos começando
 * por 9. Estrangeiro: 8 a 15 dígitos (E.164); o servidor decide o resto.
 */
export function checkPhone(value: string | null | undefined): PhoneCheck {
    if (!value || value.trim() === '' || value.trim() === '+55') {
        return 'empty';
    }

    if (isForeign(value)) {
        const length = digitsOf(value).length;

        return length < 8 ? 'incomplete' : length > 15 ? 'invalid' : 'ok';
    }

    const national = brazilianNational(value);

    if (national.length < 11) {
        return national.length === 10 && national[2] !== '9'
            ? 'invalid'
            : 'incomplete';
    }

    const ddd = Number(national.slice(0, 2));

    if (ddd < 11 || national[1] === '0' || national[2] !== '9') {
        return 'invalid';
    }

    return 'ok';
}

export const PHONE_MESSAGES: Record<Exclude<PhoneCheck, 'ok'>, string> = {
    empty: 'Informe o celular (com DDD) para enviar por SMS ou WhatsApp.',
    incomplete: 'Celular incompleto: informe DDD e os 9 dígitos.',
    invalid:
        'Informe um celular válido com DDD, por exemplo +55 11 91234-5678.',
};
