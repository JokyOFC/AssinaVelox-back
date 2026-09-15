import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/common';

/** English — same keys and placeholders as the PT-BR reference. */
export default {
    'common.pages.one': '{count} page',
    'common.pages.other': '{count} pages',
    'common.days.one': '{count} day',
    'common.days.other': '{count} days',
    'common.progress': '{done} of {total}',
    'common.copy': 'Copy',
    'common.copied': 'Copied',
    'common.copy_failed': 'Could not copy',
    'common.close': 'Close',
    'common.cancel': 'Cancel',
    'common.back_to_document': 'Back to the document',
    'common.terms': 'Terms of use',
    'common.privacy': 'Privacy notice',
    'common.portuguese_only': '(in Portuguese)',
    'common.channel.email': 'email',
    'common.channel.sms': 'SMS',
    'common.channel.whatsapp': 'WhatsApp',
    'common.verb.approve': 'approving',
    'common.verb.sign': 'signing',
    'common.session_expired':
        'Your session has expired. Reload the page and confirm the code again.',
} satisfies NamespaceOf<typeof reference>;
