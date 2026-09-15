import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/common';

/** Español — mismas claves y marcadores que la referencia en PT-BR. */
export default {
    'common.pages.one': '{count} página',
    'common.pages.other': '{count} páginas',
    'common.days.one': '{count} día',
    'common.days.other': '{count} días',
    'common.progress': '{done} de {total}',
    'common.copy': 'Copiar',
    'common.copied': 'Copiado',
    'common.copy_failed': 'No fue posible copiar',
    'common.close': 'Cerrar',
    'common.cancel': 'Cancelar',
    'common.back_to_document': 'Volver al documento',
    'common.terms': 'Términos de uso',
    'common.privacy': 'Aviso de privacidad',
    'common.portuguese_only': '(en portugués)',
    'common.channel.email': 'correo electrónico',
    'common.channel.sms': 'SMS',
    'common.channel.whatsapp': 'WhatsApp',
    'common.verb.approve': 'aprobar',
    'common.verb.sign': 'firmar',
    'common.session_expired':
        'Su sesión expiró. Recargue la página y confirme el código de nuevo.',
} satisfies NamespaceOf<typeof reference>;
