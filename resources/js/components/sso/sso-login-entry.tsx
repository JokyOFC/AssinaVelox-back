import { useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { start } from '@/routes/sso/login';

/**
 * "Entrar com SSO" da tela de login (Fase 3 §3.9 — docs/fase-3/sso.md §6). Só aparece com
 * `features.sso_oidc` ou `features.sso_saml` (sem organização, vale o interruptor global).
 * O domínio do e-mail leva à organização e ao provedor de identidade; o servidor devolve a
 * navegação para o provedor (Inertia::location). Entra o USUÁRIO do painel — nada muda para
 * quem assina documentos.
 */
export function SsoLoginEntry() {
    const { features } = usePage().props;
    const available =
        features?.sso_oidc === true || features?.sso_saml === true;
    const [open, setOpen] = useState(false);
    const form = useForm({ email: '' });

    if (!available) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(start.url());
    };

    return (
        <div className="flex flex-col gap-3" data-test="sso-login">
            <div className="text-muted-foreground flex items-center gap-3 text-[12px]">
                <span className="bg-border h-px flex-1" />
                ou
                <span className="bg-border h-px flex-1" />
            </div>

            {/* A tela de login usa tabIndex 1–6; 4 (o mesmo do "Entrar", que vem antes no DOM)
                põe o SSO logo depois do "Entrar" no Tab, e não depois de "Criar conta grátis". */}
            {!open ? (
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    className="w-full"
                    tabIndex={4}
                    onClick={() => setOpen(true)}
                >
                    Entrar com SSO
                </Button>
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-2">
                    <Label htmlFor="sso_email">E-mail corporativo</Label>
                    <Input
                        id="sso_email"
                        type="email"
                        autoComplete="email"
                        required
                        autoFocus
                        placeholder="voce@suaempresa.com.br"
                        className="shadow-card h-10"
                        tabIndex={4}
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        aria-invalid={!!form.errors.email}
                    />
                    <InputError message={form.errors.email} />
                    <Button
                        type="submit"
                        variant="outline"
                        size="lg"
                        className="w-full"
                        tabIndex={4}
                        disabled={form.processing}
                    >
                        {form.processing && <Spinner />}
                        Continuar com o login da empresa
                    </Button>
                    <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                        Você será levado ao provedor de identidade da sua
                        empresa e voltará já conectado.
                    </p>
                </form>
            )}
        </div>
    );
}
