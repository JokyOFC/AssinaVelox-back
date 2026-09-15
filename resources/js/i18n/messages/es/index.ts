import type { Messages } from '../pt_BR';
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

/** Español. Los textos jurídicos de la página son traducción de cortesía (ver `consent.*`). */
export const es: Messages = {
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
};
