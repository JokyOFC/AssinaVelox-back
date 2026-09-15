import capture from './capture';
import common from './common';
import consent from './consent';
import fields from './fields';
import layout from './layout';
import otp from './otp';
import pdf from './pdf';
import receipt from './receipt';
import refusal from './refusal';
import sign from './sign';
import signature from './signature';

/**
 * Dicionário de REFERÊNCIA (PT-BR). As chaves daqui são a lista fechada:
 * `en` e `es` são tipados contra ela (`satisfies`), então uma chave faltando ou
 * sobrando quebra o `tsc`; o teste I18n/KeyParityTest compara os arquivos.
 */
export const ptBR = {
    ...common,
    ...layout,
    ...sign,
    ...otp,
    ...consent,
    ...fields,
    ...receipt,
    ...refusal,
    ...capture,
    ...signature,
    ...pdf,
} as const;

export type MessageKey = keyof typeof ptBR;

export type Messages = { readonly [K in MessageKey]: string };

/** Tipo de um arquivo de namespace de outro idioma: mesmas chaves, qualquer texto. */
export type NamespaceOf<T> = { readonly [K in keyof T]: string };
