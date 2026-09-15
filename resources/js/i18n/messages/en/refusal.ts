import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/refusal';

export default {
    'refusal.title_signature': 'Decline to sign?',
    'refusal.title_approval': 'Decline the approval?',
    'refusal.description':
        '{organization} will receive the reason you give. Declining closes this document and cannot be undone by you.',
    'refusal.reason_label': 'Reason for declining',
    'refusal.placeholder':
        'E.g.: the rent amount is different from what was agreed.',
    'refusal.min': 'Minimum of {min} characters.',
    'refusal.submit_signature': 'Decline to sign',
    'refusal.submit_approval': 'Decline approval',
    'delegation.done_title': 'Delegation recorded',
    'delegation.done_close': 'You can close this page.',
    'delegation.error_network':
        'Could not send right now. Check your connection and try again.',
    'delegation.error_generic': 'The delegation could not be recorded.',
    'delegation.title': 'Delegation',
    'delegation.received_from':
        'You received this document by delegation from {name}. The acceptance you record is yours, with your own code and your own evidence.',
    'delegation.pending':
        'You asked to delegate to {name} ({email}) on {date}. The sender still has to decide; until then, the participation remains yours.',
    'delegation.rejected':
        'The sender declined the delegation request{note}. The participation remains yours.',
    'delegation.rejected_note': ': “{note}”',
    'delegation.prompt':
        'Should someone else respond? Name who will take part in your place.',
    'delegation.open': 'Delegate to someone else',
    'delegation.dialog_title_signature': 'Delegate the signature?',
    'delegation.dialog_title_approval': 'Delegate the approval?',
    'delegation.dialog_intro':
        'The person you name receives their own invitation, confirms the code sent to their email and records their own acceptance.',
    'delegation.dialog_requires_confirmation':
        'The sender needs to confirm first; until then, the participation remains yours.',
    'delegation.dialog_immediate':
        'As soon as you confirm, your link stops working.',
    'delegation.name': 'Full name',
    'delegation.email': 'Email',
    'delegation.reason': 'Reason',
    'delegation.reason_placeholder':
        'E.g.: the person named handles this contract at the company.',
    'delegation.dialog_video':
        'The person named will also need to record a short video of their face before accepting.',
    'delegation.reason_hint':
        'Minimum of {min} characters. Goes to the sender and to the evidence page.',
    'delegation.submit': 'Delegate',
} satisfies NamespaceOf<typeof reference>;
