import type { NamespaceOf } from '../pt_BR';
import type reference from '../pt_BR/pdf';

export default {
    'pdf.render_failed': 'Could not draw this page.',
    'pdf.loading': 'Loading the document…',
    'pdf.retry': 'Try again',
    'pdf.previous': 'Previous page',
    'pdf.next': 'Next page',
    'pdf.page_of': 'Page {page} of {total}',
    'pdf.converting': 'Converting the file to PDF',
    'pdf.converting_hint':
        'The preview appears as soon as the conversion finishes.',
    'pdf.blocked_title': 'File blocked for preparation',
    'pdf.failed_title': 'Failed to process the file',
    'pdf.blocked_body':
        'PDFs protected by a password or that already contain a digital signature cannot receive fields. Upload a version without protection.',
    'pdf.failed_body':
        'We could not prepare this file. Remove it and upload a valid PDF.',
    'pdf.zoom_out': 'Zoom out',
    'pdf.zoom_fit': 'Fit to width',
    'pdf.zoom_in': 'Zoom in',
    'pdf.error.forbidden': 'You do not have permission to view this document.',
    'pdf.error.not_found': 'The file is no longer available.',
    'pdf.error.conflict': 'The document is still being processed.',
    'pdf.error.network': 'Could not load the document. Try again.',
    'pdf.error.password':
        'This PDF is password protected and cannot be prepared for signing.',
    'pdf.error.invalid': 'Could not read this PDF. The file may be corrupted.',
    'pdf.error.unknown':
        'Could not display the document. Download the file to check it.',
} satisfies NamespaceOf<typeof reference>;
