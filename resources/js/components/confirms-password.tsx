import { useCallback, useRef, useState } from 'react';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/**
 * Confirmação de senha **antes** da ação, no lugar do desvio do middleware
 * `password.confirm` (ROUTES §2.15 e §2.17, modal `ConfirmsPassword`).
 *
 * Por que existir: `password.confirm` num POST é um beco sem saída. O middleware
 * do Laravel só guarda `url.intended` quando a requisição interrompida é um GET
 * (`Redirector::guest()`); num POST ele guarda o *referer*. O usuário confirma a
 * senha, volta para a mesma página — e a ação que ele pediu simplesmente não
 * aconteceu, sem aviso nenhum. Reproduzido em teste: `POST billing.cancel` sem
 * senha confirmada grava `url.intended` = página anterior, não a rota do POST.
 *
 * A correção é pedir a senha antes: o modal consulta
 * `GET /user/confirmed-password-status` e, se ainda não estiver confirmada,
 * chama `POST /user/confirm-password` (ambos do Fortify, ambos com resposta JSON
 * quando o `Accept` pede JSON) e só então executa a ação. O middleware continua
 * na rota — ele é a garantia do servidor; este modal é só a ergonomia do cliente.
 *
 * A senha vai por `fetch` com `Accept: application/json` e o token XSRF do
 * cookie; nunca é guardada em estado depois da chamada nem enviada ao Inertia.
 */

const STATUS_URL = '/user/confirmed-password-status';
const CONFIRM_URL = '/user/confirm-password';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function alreadyConfirmed(): Promise<boolean> {
    try {
        const response = await fetch(STATUS_URL, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return false;
        }

        const body: unknown = await response.json();

        return (
            typeof body === 'object' &&
            body !== null &&
            (body as { confirmed?: unknown }).confirmed === true
        );
    } catch {
        // Rede indisponível: pedir a senha é o caminho seguro, nunca pular.
        return false;
    }
}

async function confirmPassword(password: string): Promise<boolean> {
    const response = await fetch(CONFIRM_URL, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ password }),
    });

    return response.ok;
}

export interface ConfirmsPassword {
    /**
     * Executa `action` com a senha já confirmada. Se a confirmação estiver
     * válida (janela do Fortify), executa direto; senão abre o modal.
     */
    ensure: (action: () => void) => void;
    /** Renderize em qualquer lugar da página; controla o próprio estado. */
    dialog: React.ReactNode;
}

export function useConfirmsPassword({
    title = 'Confirme sua senha',
    description = 'Esta é uma área protegida. Confirme sua senha para continuar.',
}: { title?: string; description?: string } = {}): ConfirmsPassword {
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | undefined>(undefined);
    const [processing, setProcessing] = useState(false);
    const pending = useRef<(() => void) | null>(null);

    const ensure = useCallback((action: () => void) => {
        pending.current = action;

        void alreadyConfirmed().then((confirmed) => {
            if (confirmed) {
                pending.current = null;
                action();

                return;
            }

            setPassword('');
            setError(undefined);
            setOpen(true);
        });
    }, []);

    const submit = useCallback(() => {
        if (password === '') {
            setError('Informe sua senha.');

            return;
        }

        setProcessing(true);

        void confirmPassword(password).then((ok) => {
            setProcessing(false);

            if (!ok) {
                setError('A senha informada está incorreta.');

                return;
            }

            const action = pending.current;
            pending.current = null;
            setPassword('');
            setOpen(false);
            action?.();
        });
    }, [password]);

    const dialog = (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    pending.current = null;
                    setPassword('');
                    setError(undefined);
                }

                setOpen(next);
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <form
                    className="grid gap-1.5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <Label htmlFor="confirm-password">Senha</Label>
                    <PasswordInput
                        id="confirm-password"
                        name="password"
                        autoComplete="current-password"
                        autoFocus
                        value={password}
                        onChange={(event) => {
                            setPassword(event.target.value);
                            setError(undefined);
                        }}
                        aria-invalid={!!error}
                    />
                    <InputError message={error} />
                </form>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => setOpen(false)}
                        disabled={processing}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={processing}>
                        {processing && <Spinner />}
                        Confirmar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );

    return { ensure, dialog };
}
