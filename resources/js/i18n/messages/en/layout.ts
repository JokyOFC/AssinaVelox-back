import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/layout';

export default {
    'layout.steps.identify': 'Confirm identity',
    'layout.steps.sign': 'Sign',
    'layout.steps.completed': 'Completed',
    'layout.requests_signature': 'requests your signature',
    'layout.logo_alt': '{name} logo',
    'layout.via': 'via',
    'layout.footer.tls': 'Protected connection (TLS)',
    'layout.footer.audit': 'Audit trail',
    'layout.footer.processed':
        'Document processed by AssinaVelox · electronic acceptance with evidence',
    'layout.language': 'Language',
    'layout.language_hint': 'Changes only the display language of this page.',
} satisfies NamespaceOf<typeof reference>;
