import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/consent';

export default {
    'consent.version': 'Versión del texto de aceptación:',
    'consent.courtesy_title': 'Traducción de cortesía',
    'consent.reviewed_title': 'Traducción revisada',
    'consent.courtesy_body':
        'Este texto está traducido para facilitar la lectura. La versión de referencia, que queda registrada como evidencia de su aceptación, es la de portugués (Brasil).',
    'consent.show_reference': 'Ver el texto de referencia (portugués)',
    'consent.hide_reference': 'Ocultar el texto de referencia',
    'privacy.show': 'Ver aviso completo',
    'privacy.hide': 'Ocultar aviso completo',
    'privacy.show_reference': 'Ver el original en portugués',
    'privacy.hide_reference': 'Ocultar el original en portugués',
} satisfies NamespaceOf<typeof reference>;
