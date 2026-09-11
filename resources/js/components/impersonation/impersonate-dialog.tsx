import { useForm } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { impersonate } from '@/routes/admin/organizations';

/**
 * "Acessar como" (painel interno › Cliente). Pede motivo e a senha do admin
 * na hora; o servidor revalida tudo (alvo elegível, senha, motivo) e registra
 * na trilha da organização e da plataforma.
 */
export function ImpersonateDialog({
    open,
    onOpenChange,
    organizationId,
    organizationName,
    target,
    ttlMinutes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    organizationId: string;
    organizationName: string;
    target: { membershipUserId: string; name: string; email: string } | null;
    ttlMinutes: number;
}) {
    const form = useForm({ user: '', reason: '', password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!target) {
            return;
        }

        form.transform((data) => ({ ...data, user: target.membershipUserId }));
        form.post(impersonate.url(organizationId), {
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.reset();
                    form.clearErrors();
                }

                onOpenChange(next);
            }}
        >
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Acessar como {target?.name}</DialogTitle>
                        <DialogDescription>
                            Sessão de suporte em {organizationName}, somente
                            leitura, por até {ttlMinutes} minutos. Sem acesso a
                            arquivos de documentos, cobrança ou credenciais. O
                            início, o fim e cada página visitada ficam
                            registrados na trilha da organização.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="bg-muted text-text-secondary rounded-[10px] px-3 py-2 text-[12.5px]">
                        {target?.name} · {target?.email}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="impersonation-reason">
                            Motivo (obrigatório)
                        </Label>
                        <Textarea
                            id="impersonation-reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="Ex.: Chamado #1234 — cliente não encontra um documento enviado."
                            rows={3}
                            maxLength={500}
                            required
                            minLength={10}
                            aria-invalid={!!form.errors.reason}
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="impersonation-password">
                            Sua senha
                        </Label>
                        <PasswordInput
                            id="impersonation-password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                            autoComplete="current-password"
                            required
                            aria-invalid={!!form.errors.password}
                        />
                        <InputError message={form.errors.password} />
                        <InputError message={form.errors.user} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                form.data.reason.trim().length < 10 ||
                                !form.data.password
                            }
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <Eye className="size-3.5" />
                            )}
                            Iniciar acesso
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
