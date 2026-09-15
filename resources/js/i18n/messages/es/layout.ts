import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/layout';

export default {
    'layout.steps.identify': 'Confirmar identidad',
    'layout.steps.sign': 'Firmar',
    'layout.steps.completed': 'Concluido',
    'layout.requests_signature': 'solicita su firma',
    'layout.logo_alt': 'Logo de {name}',
    'layout.via': 'vía',
    'layout.footer.tls': 'Conexión protegida (TLS)',
    'layout.footer.audit': 'Registro de auditoría',
    'layout.footer.processed':
        'Documento procesado por AssinaVelox · aceptación electrónica con evidencias',
    'layout.language': 'Idioma',
    'layout.language_hint':
        'Cambia solo el idioma de visualización de esta página.',
} satisfies NamespaceOf<typeof reference>;
