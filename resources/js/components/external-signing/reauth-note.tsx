import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { send as otpSend } from '@/routes/sign/otp';
import { Note } from './parts';

/**
 * Sem sessão do código (quem já aceitou e voltou depois da janela de download).
 *
 * `available` = a página consegue mostrar o campo do código aqui mesmo: o servidor mandou a
 * prop `otp` no comprovante (`#certificate-reauth`). Hoje `SignerPageProps` só faz isso
 * quando o envio do certificado A1 está aberto para a pessoa — para o token (A3) e o gov.br
 * isso ainda é pendência de integração. Sem o campo, a tela NÃO oferece "Receber código"
 * (o código chegaria e não haveria onde digitá-lo); diz a verdade e orienta.
 */
export function ReauthNote({
    token,
    message,
    available = false,
}: {
    token: string;
    message?: string | null;
    available?: boolean;
}) {
    const requestCode = () => {
        router.post(
            otpSend(token).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    document
                        .getElementById('certificate-reauth')
                        ?.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center',
                        }),
            },
        );
    };

    if (!available) {
        return (
            <Note tone="warning">
                A confirmação de identidade desta etapa expirou neste navegador,
                e esta tela ainda não consegue pedir um novo código. Se você
                acabou de assinar em outro aparelho, continue por lá; se não,
                fale com quem enviou o documento. O seu aceite eletrônico
                continua valendo.
                {message && <span className="mt-1 block">{message}</span>}
            </Note>
        );
    }

    return (
        <Note tone="warning">
            Para continuar, confirme sua identidade de novo: enviaremos um
            código para você informar aqui mesmo nesta página.
            {message && <span className="mt-1 block">{message}</span>}
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={requestCode}
            >
                Receber código
            </Button>
        </Note>
    );
}
