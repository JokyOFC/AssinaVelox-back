import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/sign';

export default {
    'sign.steps.approve': 'Approve',
    'sign.steps.view': 'Follow',
    'sign.file_fallback': 'File {position}',
    'sign.file_label': '{position}. {name}',
    'sign.other.signs_after_you': 'signs after you',
    'sign.other.not_signed_yet': 'has not signed yet',
    'sign.error.signature_too_large':
        'The signature image is too large. Clear the pad and draw a simpler stroke, or upload a smaller image.',
    'sign.invalid.title': 'Invalid link',
    'sign.invalid.body':
        'This link does not exist or was replaced. Check the most recent email.',
    'sign.expired.title': 'Deadline ended',
    'sign.expired.body':
        'The deadline to sign this document ended on {date}. Contact {sender} ({organization}).',
    'sign.canceled.title': 'Document canceled',
    'sign.canceled.body': '{organization} canceled this signature request.',
    'sign.refused.title_approve': 'Approval declined',
    'sign.refused.title_sign': 'Signature declined',
    'sign.refused.at_approve':
        'You declined to approve this document on {date}.',
    'sign.refused.at_sign': 'You declined to sign this document on {date}.',
    'sign.refused.reason': 'Reason given: “{reason}”',
    'sign.refused.approve': 'You declined to approve this document.',
    'sign.refused.sign': 'You declined to sign this document.',
    'sign.receipt_fallback': 'Your acceptance was recorded.',
    'sign.tab.receipt': 'Receipt · {title}',
    'sign.tab.document': 'Document · {title}',
    'sign.tab.approve': 'Approve · {title}',
    'sign.tab.witness': 'Sign as a witness · {title}',
    'sign.tab.sign': 'Sign · {title}',
    'sign.heading.certificate': 'Confirm your identity to send the certificate',
    'sign.heading.approve': 'Confirm your identity to approve',
    'sign.heading.view': 'Confirm your identity to see the document',
    'sign.files.view_label': 'Files in this document',
    'sign.files.sign_label': 'Check all files',
    'sign.files.not_opened': 'Not opened yet',
    'sign.files.pending.one': '{count} pending field',
    'sign.files.pending.other': '{count} pending fields',
    'sign.files.done': 'Opened · nothing pending',
    'sign.code_confirmed': 'Code confirmed',
    'sign.viewer.title': 'Copy for follow-up',
    'sign.viewer.notice_fallback':
        'You received this document for follow-up. You do not need to sign or approve.',
    'sign.viewer.readonly':
        'As a viewer, you do not record acceptance or refusal. This screen is read-only and does not change the progress of the document.',
    'sign.viewer.final_completed': 'Final copy — completed on {date}',
    'sign.viewer.final': 'Final copy',
    'sign.viewer.download_file': 'Download {file}',
    'sign.viewer.download_final': 'Download final copy',
    'sign.viewer.available_when_done': 'Available when everyone finishes',
    'sign.button_fallback': 'Sign document',
    'sign.panel.title_approve': 'Your approval',
    'sign.panel.title_witness': 'Your signature as a witness',
    'sign.panel.title_sign': 'Your signature',
    'sign.panel.approve_intro':
        'You approve the content {what}. There is no visual representation of a signature: what records your {bold} is the acceptance below.',
    'sign.panel.approve_files': 'of the files',
    'sign.panel.approve_document': 'of the document',
    'sign.panel.approve_bold': 'electronic approval',
    'sign.panel.witness_intro':
        'You take part as a {bold}. The image is the visual representation of your signature; what records your statement is the acceptance below, with the witness statement.',
    'sign.panel.witness_bold': 'witness',
    'sign.panel.sign_intro':
        'Choose how you want to sign. The image is the {bold} of your signature; what records your will is the acceptance below.',
    'sign.panel.sign_bold': 'visual representation',
    'sign.panel.initials': 'Your initials',
    'sign.warn.open_all.one':
        'Open and check all files before {verb}. Missing: {files}.',
    'sign.warn.open_all.other':
        'Open and check all files before {verb}. Missing: {files}.',
    'sign.warn.file_not_arrived':
        ' The open file did not arrive — use “Try again” or “Download PDF” in the document bar.',
    'sign.warn.document_not_arrived':
        'The document did not arrive. Use “Try again” or “Download PDF” in the document bar — you can only sign after checking what is being signed.',
    'sign.warn.document_loading':
        'Wait for the document to finish loading to sign.',
    'sign.warn.pending.one':
        '{count} required field left — use “Next field” in the document bar.',
    'sign.warn.pending.other':
        '{count} required fields left — use “Next field” in the document bar.',
    'sign.warn.cpf': 'Check the CPF entered: the digits do not match.',
    'sign.warn.video': 'Before {verb}, send the short video.',
    'sign.warn.capture': 'Before {verb}, send: {items}.',
    'sign.refuse.approve': 'Decline approval',
    'sign.refuse.sign': 'Decline to sign',
    'sign.locked.email':
        'The document is shown after you confirm the code sent to your email.',
    'sign.locked.phone':
        'The document is shown after you confirm the code sent by {channel} to your phone.',
    'sign.locked.never_public': 'It is never available at a public address.',
    'sign.participants.title': 'Participants',
    'sign.participants.you': 'you',
    'sign.participants.approved': 'Approved',
    'sign.participants.signed': 'Signed',
    'sign.participants.refused': 'Declined',
    'sign.participants.after_you': 'after you',
    'sign.participants.signs_after_you': 'signs after you',
    'sign.participants.pending': 'pending',
} satisfies NamespaceOf<typeof reference>;
