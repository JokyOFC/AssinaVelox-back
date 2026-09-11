import type { ReactNode } from 'react';
import type {
    PresenceLegal,
    PresenceSender,
} from '@/components/in-person/types';
import SignerLayout from '@/layouts/signer-layout';

/**
 * Casca da página pública do lote: a mesma do fluxo do signatário, sem conta
 * e sem menu. Componente de layout (`Page.layout = BatchShell`).
 */
export function BatchShell({
    children,
    sender = null,
    legal,
}: {
    children: ReactNode;
    sender?: PresenceSender | null;
    legal?: PresenceLegal;
}) {
    return (
        <SignerLayout
            sender={sender}
            documentTitle="Documentos pendentes"
            step={null}
            privacyUrl={legal?.privacy_url ?? null}
            termsUrl={legal?.terms_url ?? null}
        >
            {children}
        </SignerLayout>
    );
}
