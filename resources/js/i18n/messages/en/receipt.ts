import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/receipt';

export default {
    'receipt.closed.expired':
        'The signing deadline ended before all acceptances, so collection was closed and there will be no final file. Your acceptance remains recorded and this receipt remains valid as proof of what you did.',
    'receipt.closed.canceled':
        'The sender canceled this request, so collection was closed and there will be no final file. Your acceptance remains recorded and this receipt remains valid as proof of what you did.',
    'receipt.closed.refused':
        'A participant declined, so collection was closed and there will be no final file. Your acceptance remains recorded and this receipt remains valid as proof of what you did.',
    'receipt.closing.completed': 'The final file is already available.',
    'receipt.closing.last_signer':
        'You were the last to sign: all required acceptances have been recorded and the final file is being prepared. As soon as it is ready, it appears here and is emailed to everyone who took part.',
    'receipt.closing.waiting':
        'You will receive the final file when all participants finish.',
    'receipt.title.completed': 'Document completed',
    'receipt.title.closed': 'Collection closed',
    'receipt.title.last_signer': 'Acceptances completed',
    'receipt.title.approved': 'You have approved',
    'receipt.title.signed': 'You have signed',
    'receipt.recorded_approval': 'Approval recorded.',
    'receipt.recorded_acceptance': 'Acceptance recorded.',
    'receipt.statement':
        'Your expression of will was recorded on {local} ({utc} UTC), authenticated by a code sent to the email {email}. Verification code: {code}. {closing}',
    'receipt.waiting_participants.one': 'Waiting for {count} participant.',
    'receipt.waiting_participants.other': 'Waiting for {count} participants.',
    'receipt.waiting_signers.one': 'Waiting for {count} signer.',
    'receipt.waiting_signers.other': 'Waiting for {count} signers.',
    'receipt.dt.receipt': 'Receipt',
    'receipt.dt.datetime': 'Date and time',
    'receipt.dt.auth': 'Authentication',
    'receipt.dt.code': 'Code',
    'receipt.dt.ip': 'Recorded IP',
    'receipt.dt.terms': 'Accepted text',
    'receipt.dt.files': 'Files',
    'receipt.dt.document_sha': 'Document SHA-256',
    'receipt.dt.final_sha': 'Final SHA-256',
    'receipt.action_fallback': 'Electronic acceptance',
    'receipt.window_closed':
        'The download window for this session has ended. For security, the receipt and the final file are only delivered to someone who has just confirmed the code sent by email — having the invitation link is not enough. When the document is completed, you will receive by email a link of your own to download the file.',
    'receipt.verify_note':
        'This receipt can be checked at any time on the public verification page, without login and without exposing personal data: {link}',
    'receipt.verify_link': 'verify with code {code}',
    'receipt.download_file': 'Download {position}. {name}',
    'receipt.file_fallback_lower': 'file {position}',
    'receipt.download_copy': 'Download copy',
    'receipt.no_final': 'No final file',
    'receipt.available_when_signed': 'Available when everyone signs',
    'receipt.evidence_report': 'Evidence report',
} satisfies NamespaceOf<typeof reference>;
