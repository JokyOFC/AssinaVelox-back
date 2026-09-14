import { Badge } from '@/components/ui/badge';
import type {
    AffiliateStatus,
    BatchStatus,
    CommissionStatus,
    ReferralStatus,
} from './types';

type Variant = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const AFFILIATE: Record<AffiliateStatus, Variant> = {
    pending: 'warning',
    approved: 'success',
    rejected: 'neutral',
    suspended: 'danger',
};

const REFERRAL: Record<ReferralStatus, Variant> = {
    active: 'success',
    held: 'warning',
    rejected: 'neutral',
};

const COMMISSION: Record<CommissionStatus, Variant> = {
    pending: 'warning',
    approved: 'info',
    paid: 'success',
    reversed: 'neutral',
};

const BATCH: Record<BatchStatus, Variant> = {
    draft: 'warning',
    paid: 'success',
    canceled: 'neutral',
};

export function AffiliateStatusBadge({
    status,
    label,
}: {
    status: AffiliateStatus;
    label: string;
}) {
    return (
        <Badge variant={AFFILIATE[status] ?? 'neutral'} dot>
            {label}
        </Badge>
    );
}

export function ReferralStatusBadge({
    status,
    label,
}: {
    status: ReferralStatus;
    label: string;
}) {
    return (
        <Badge variant={REFERRAL[status] ?? 'neutral'} dot>
            {label}
        </Badge>
    );
}

export function CommissionStatusBadge({
    status,
    label,
}: {
    status: CommissionStatus;
    label: string;
}) {
    return (
        <Badge variant={COMMISSION[status] ?? 'neutral'} dot>
            {label}
        </Badge>
    );
}

export function BatchStatusBadge({
    status,
    label,
}: {
    status: BatchStatus;
    label: string;
}) {
    return (
        <Badge variant={BATCH[status] ?? 'neutral'} dot>
            {label}
        </Badge>
    );
}

/** 1000 pontos-base → "10%" ; 1250 → "12,5%". */
export function formatRateBp(bp: number): string {
    return `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(bp / 100)}%`;
}
