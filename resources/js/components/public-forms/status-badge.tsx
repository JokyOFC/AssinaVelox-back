import { Badge } from '@/components/ui/badge';
import type { PublicFormStatus, SubmissionStatus } from './types';

const FORM_VARIANT: Record<
    PublicFormStatus,
    'success' | 'warning' | 'draft' | 'danger'
> = {
    active: 'success',
    paused: 'warning',
    draft: 'draft',
    revoked: 'danger',
};

export function FormStatusBadge({
    status,
    label,
    expired = false,
}: {
    status: PublicFormStatus;
    label: string;
    expired?: boolean;
}) {
    if (expired && status !== 'revoked') {
        return <Badge variant="neutral">Encerrado</Badge>;
    }

    return <Badge variant={FORM_VARIANT[status]}>{label}</Badge>;
}

const SUBMISSION_VARIANT: Record<
    SubmissionStatus,
    'info' | 'warning' | 'success' | 'danger' | 'neutral'
> = {
    pending_confirmation: 'neutral',
    processing: 'info',
    pending_review: 'warning',
    sent: 'success',
    rejected: 'neutral',
    failed: 'danger',
};

export function SubmissionStatusBadge({
    status,
    label,
}: {
    status: SubmissionStatus;
    label: string;
}) {
    return <Badge variant={SUBMISSION_VARIANT[status]}>{label}</Badge>;
}
