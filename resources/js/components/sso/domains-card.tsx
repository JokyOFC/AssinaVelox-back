import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { destroy, store, verify } from '@/routes/settings/sso/domains';
import type { SsoDomainProps } from './types';

/**
 * Domínios do login corporativo (docs/fase-3/sso.md §4): só entram pelo SSO e-mails de
 * domínio VERIFICADO por registro TXT. Um domínio verificado pertence a uma única organização.
 */
export function DomainsCard({
    domains,
    maxDomains,
}: {
    domains: SsoDomainProps[];
    maxDomains: number;
}) {
    const add = useForm({ domain: '' });
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Record<string, string>;
    const [busy, setBusy] = useState<string | null>(null);
    const [removing, setRemoving] = useState<SsoDomainProps | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        add.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => add.reset('domain'),
        });
    };

    const check = (domain: SsoDomainProps) => {
        setBusy(domain.ulid);
        router.post(
            verify.url(domain.ulid),
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
            <Heading
                variant="small"
                title="Domínios de e-mail"
                description="Só entra pelo login corporativo quem tem e-mail de um domínio verificado desta organização."
            />

            {domains.length === 0 ? (
                <p className="text-muted-foreground text-[13px]">
                    Nenhum domínio ainda. Adicione o domínio dos e-mails da
                    empresa e comprove que ele é seu com um registro TXT no DNS.
                </p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {domains.map((domain) => (
                        <li
                            key={domain.ulid}
                            className="border-border flex flex-col gap-2 rounded-[10px] border p-3.5"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold">
                                    {domain.domain}
                                    {domain.verified ? (
                                        <Badge variant="success">
                                            Verificado
                                        </Badge>
                                    ) : (
                                        <Badge variant="warning">
                                            Aguardando verificação
                                        </Badge>
                                    )}
                                </div>
                                <div className="flex gap-2">
                                    {!domain.verified && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="xs"
                                            disabled={busy === domain.ulid}
                                            onClick={() => check(domain)}
                                        >
                                            {busy === domain.ulid && (
                                                <Spinner />
                                            )}
                                            Verificar
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="xs"
                                        onClick={() => setRemoving(domain)}
                                    >
                                        Remover
                                    </Button>
                                </div>
                            </div>
                            {domain.verified ? (
                                <p className="text-muted-foreground text-[12.5px]">
                                    Verificado em{' '}
                                    {formatDateTime(domain.verified_at)}.
                                </p>
                            ) : (
                                <div className="bg-sidebar flex flex-col gap-1.5 rounded-lg p-2.5 text-[12.5px]">
                                    <span className="text-text-secondary">
                                        Crie este registro TXT no DNS do
                                        domínio:
                                    </span>
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <span className="text-muted-foreground shrink-0">
                                            Nome:
                                        </span>
                                        <code className="truncate font-mono">
                                            {domain.record_name}
                                        </code>
                                        <CopyButton
                                            value={domain.record_name}
                                        />
                                    </span>
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <span className="text-muted-foreground shrink-0">
                                            Valor:
                                        </span>
                                        <code className="truncate font-mono">
                                            {domain.record_value}
                                        </code>
                                        <CopyButton
                                            value={domain.record_value}
                                        />
                                    </span>
                                    {domain.last_check_status ===
                                        'not_found' && (
                                        <span className="text-warning">
                                            Última consulta (
                                            {formatDateTime(
                                                domain.last_checked_at,
                                            )}
                                            ): registro não encontrado.
                                        </span>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <InputError message={errors.verify} />

            {domains.length < maxDomains && (
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-start gap-2"
                >
                    <div className="grid min-w-[220px] flex-1 gap-1">
                        <Input
                            aria-label="Domínio"
                            value={add.data.domain}
                            placeholder="suaempresa.com.br"
                            onChange={(e) =>
                                add.setData('domain', e.target.value)
                            }
                            aria-invalid={!!add.errors.domain}
                        />
                        <InputError message={add.errors.domain} />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={add.processing}
                    >
                        {add.processing && <Spinner />}
                        Adicionar domínio
                    </Button>
                </form>
            )}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                destructive
                title="Remover domínio"
                description={`Quem usa e-mail de ${removing?.domain ?? ''} deixa de entrar pelo login corporativo desta organização.`}
                confirmLabel="Remover domínio"
                onConfirm={() => {
                    if (removing) {
                        router.delete(destroy.url(removing.ulid), {
                            preserveScroll: true,
                            onFinish: () => setRemoving(null),
                        });
                    }
                }}
            />
        </section>
    );
}
