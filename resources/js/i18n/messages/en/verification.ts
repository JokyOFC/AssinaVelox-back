import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/verification';

export default {
    'verification.title': 'Facial verification with document',
    'verification.confirm_code_first':
        'Confirm the code before sending the photos for verification.',
    'verification.take_photos_first':
        'Take the three photos above — face, front and back of the document — before sending.',
    'verification.document_type': 'Document type',
    'verification.submit': 'Send for verification',
    'verification.resubmit': 'Send the photos again',
    'verification.sent':
        'Photos sent to the provider. The result appears here.',
    'verification.waiting': 'Waiting for the result reported by the provider…',
    'verification.attempts': 'Attempts: {used} of {max}',
    'verification.no_attempts_left':
        'There are no attempts left. Contact the person who sent the document: only they can allow a new submission.',
    'verification.captures_changed':
        'A photo was retaken after the result. Send the photos again so the provider compares the current images.',
    'verification.warn':
        'Before {verb}, send the photos for the facial verification with document and wait for the result reported by the provider.',
    'verification.consent_courtesy':
        'Courtesy translation: the authorization recorded is the reference text, in Portuguese.',
    'verification.preview':
        'After the code, the three photos (face, front and back of the document) are sent to the provider {provider}, which compares the photo taken on the spot with the photo on the document. Acceptance is only released with the “approved” result reported by the provider.',
    'verification.error.unknown_delivery':
        'We did not receive confirmation of the submission. Check your connection: if the photos arrived, the status is updated here in a moment.',
    'verification.error.not_required':
        'The verification is no longer requested from you. Reload the page.',
    'verification.error.generic':
        'The photos could not be sent for verification. Try again.',
    'verification.error.poll':
        'The result could not be checked right now. Check your connection: checking continues on its own.',
} satisfies NamespaceOf<typeof reference>;
