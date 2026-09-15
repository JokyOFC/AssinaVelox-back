import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/consent';

export default {
    'consent.version': 'Acceptance text version:',
    'consent.courtesy_title': 'Courtesy translation',
    'consent.reviewed_title': 'Reviewed translation',
    'consent.courtesy_body':
        'This text is translated to make it easier to read. The reference version, which is recorded as evidence of your acceptance, is the one in Portuguese (Brazil).',
    'consent.show_reference': 'Show the reference text (Portuguese)',
    'consent.hide_reference': 'Hide the reference text',
    'privacy.show': 'Show full notice',
    'privacy.hide': 'Hide full notice',
    'privacy.show_reference': 'Show the original in Portuguese',
    'privacy.hide_reference': 'Hide the original in Portuguese',
} satisfies NamespaceOf<typeof reference>;
