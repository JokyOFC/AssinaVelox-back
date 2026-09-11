import type { ReactNode } from 'react';
import type {
    KioskProps,
    PresenceLegal,
    PresenceSender,
} from '@/components/in-person/types';
import SignerLayout from '@/layouts/signer-layout';

/**
 * Casca do dispositivo presencial: a mesma do fluxo público do signatário
 * (sem conta, sem menu do painel), com o título do documento da sessão. É um
 * componente de layout (`Page.layout = KioskShell`): recebe as props da
 * página.
 */
export function KioskShell({
    children,
    sender = null,
    legal,
    kiosk = null,
}: {
    children: ReactNode;
    sender?: PresenceSender | null;
    legal?: PresenceLegal;
    kiosk?: KioskProps['kiosk'];
}) {
    return (
        <SignerLayout
            sender={sender}
            documentTitle={
                kiosk ? `Presencial · ${kiosk.envelope.title}` : 'Presencial'
            }
            step={null}
            privacyUrl={legal?.privacy_url ?? null}
            termsUrl={legal?.terms_url ?? null}
        >
            {children}
        </SignerLayout>
    );
}
