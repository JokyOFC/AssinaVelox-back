import { Head, Link, router } from '@inertiajs/react';
import { KeyRound, Plus, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { TokenStateBadge } from '@/components/integrations/badges';
import { CodeBlock } from '@/components/integrations/code-block';
import { CreateKeyDialog } from '@/components/integrations/create-key-dialog';
import {
    IntegrationsCard,
    IntegrationsShell,
} from '@/components/integrations/integrations-shell';
import { OneTimeSecret } from '@/components/integrations/one-time-secret';
import type {
    AbilityOption,
    ApiTokenRow,
    IntegrationsNavigation,
    RevealedToken,
} from '@/components/integrations/types';
import { Button } from '@/components/ui/button';
import { formatDateMedium, formatRelativeDateTime, plural } from '@/lib/format';
import {
    index as integrationsIndex,
    keys as keysRoute,
} from '@/routes/integrations';
import { destroy } from '@/routes/integrations/keys';

interface KeysProps {
    navigation: IntegrationsNavigation;
    tokens: ApiTokenRow[];
    abilities: AbilityOption[];
    limits: { max_active: number; active: number; max_expiration_days: number };
    revealed_token: RevealedToken | null;
}

/**
 * Integrações → Chaves (Fase 2 §2.15; mock "App - Integracoes"). O texto da
 * chave chega UMA vez, na resposta da criação; a página o guarda só no estado
 * local e o apaga do histórico do navegador. A listagem nunca traz o texto.
 */
export default function IntegrationsKeys({
    navigation,
    tokens,
    abilities,
    limits,
    revealed_token,
}: KeysProps) {
    const [creating, setCreating] = useState(false);
    const [revealed, setRevealed] = useState<RevealedToken | null>(null);
    const [revoking, setRevoking] = useState<ApiTokenRow | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (revealed_token) {
            setRevealed(revealed_token);
            // Tira o texto da chave do estado do histórico (voltar/avançar não o traz).
            router.replaceProp('revealed_token', null);
        }
    }, [revealed_token]);

    const atLimit = limits.active >= limits.max_active;

    const revoke = () => {
        if (!revoking) {
            return;
        }

        setProcessing(true);
        router.delete(destroy.url(revoking.id), {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setRevoking(null);
            },
        });
    };

    const newKeyButton = (
        <Button
            onClick={() => setCreating(true)}
            disabled={atLimit}
            title={
                atLimit
                    ? `Limite de ${limits.max_active} chaves ativas atingido`
                    : undefined
            }
        >
            <Plus className="size-[15px]" strokeWidth={2.5} />
            Nova chave
        </Button>
    );

    return (
        <>
            <Head title="Chaves de API" />
            <IntegrationsShell
                active="keys"
                navigation={navigation}
                title="Chaves de API"
                subtitle="Credenciais para sistemas e conectores (n8n, Zapier, Make) chamarem a API em nome da sua conta."
            >
                <div className="flex flex-wrap items-start gap-5">
                    <div className="flex min-w-0 flex-[2_1_520px] flex-col gap-5">
                        {revealed && (
                            <OneTimeSecret
                                title={`Chave "${revealed.name}" criada`}
                                value={revealed.token}
                                onDismiss={() => setRevealed(null)}
                            />
                        )}

                        <IntegrationsCard
                            title="Chaves"
                            description={`${plural(limits.active, 'chave ativa', 'chaves ativas')} de ${limits.max_active}. A chave completa é exibida uma única vez, na criação.`}
                            actions={tokens.length > 0 ? newKeyButton : null}
                        >
                            {tokens.length === 0 ? (
                                <EmptyState
                                    icon={KeyRound}
                                    title="Nenhuma chave ainda"
                                    description="Crie uma chave para integrar seu sistema ou um conector no-code. Dê a ela só as permissões de que a integração precisa."
                                    action={newKeyButton}
                                />
                            ) : (
                                <div className="overflow-x-auto">
                                    <div className="min-w-[720px]">
                                        <div className="text-muted-foreground bg-background border-border grid h-[38px] grid-cols-[minmax(0,1.6fr)_1.1fr_1.2fr_1fr_.8fr_90px] items-center gap-3 border-y px-5 text-[12px] font-semibold">
                                            <span>Nome</span>
                                            <span>Chave</span>
                                            <span>Criada</span>
                                            <span>Último uso</span>
                                            <span>Status</span>
                                            <span />
                                        </div>
                                        <ul className="divide-border divide-y">
                                            {tokens.map((token) => (
                                                <TokenRow
                                                    key={token.id}
                                                    token={token}
                                                    onRevoke={() =>
                                                        setRevoking(token)
                                                    }
                                                />
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            )}
                        </IntegrationsCard>
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_280px] flex-col gap-4">
                        <CodeBlock
                            label="Como usar a chave"
                            samples={[
                                {
                                    key: 'curl',
                                    label: 'cURL',
                                    code: `curl ${window.location.origin}/api/v1/envelopes \\\n  -H "Authorization: Bearer $ASSINAVELOX_TOKEN" \\\n  -H "Accept: application/json"`,
                                },
                            ]}
                        />
                        <IntegrationsCard
                            title="Boas práticas"
                            className="pb-4"
                        >
                            <ul className="text-text-secondary flex flex-col gap-2 px-5 text-[13px] leading-[1.55]">
                                <li className="flex gap-2">
                                    <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" />
                                    Uma chave por integração, com o mínimo de
                                    permissões.
                                </li>
                                <li className="flex gap-2">
                                    <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" />
                                    Guarde a chave no servidor ou no cofre do
                                    conector — nunca no navegador ou no
                                    código-fonte.
                                </li>
                                <li className="flex gap-2">
                                    <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" />
                                    A chave age com as suas permissões atuais:
                                    se você perder uma permissão, ela também
                                    perde.
                                </li>
                                <li className="flex gap-2">
                                    <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" />
                                    Revogar é imediato e remove as assinaturas
                                    de webhook criadas pela chave.
                                </li>
                            </ul>
                            <div className="px-5 pt-3">
                                <Link
                                    href={integrationsIndex.url()}
                                    className="text-primary text-[13px] font-semibold"
                                >
                                    Ler a documentação →
                                </Link>
                            </div>
                        </IntegrationsCard>
                    </aside>
                </div>
            </IntegrationsShell>

            <CreateKeyDialog
                open={creating}
                onOpenChange={setCreating}
                abilities={abilities}
                maxExpirationDays={limits.max_expiration_days}
            />

            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                title={`Revogar a chave "${revoking?.name ?? ''}"?`}
                description={
                    revoking && revoking.subscriptions > 0
                        ? `Chamadas com esta chave passam a receber 401 imediatamente, e ${plural(revoking.subscriptions, 'assinatura de webhook criada por ela será removida', 'assinaturas de webhook criadas por ela serão removidas')}. Não é possível desfazer.`
                        : 'Chamadas com esta chave passam a receber 401 imediatamente. Não é possível desfazer.'
                }
                confirmLabel="Revogar chave"
                destructive
                processing={processing}
                onConfirm={revoke}
            />
        </>
    );
}

IntegrationsKeys.layout = {
    breadcrumbs: [
        { title: 'API e integrações', href: integrationsIndex() },
        { title: 'Chaves', href: keysRoute() },
    ],
};

function TokenRow({
    token,
    onRevoke,
}: {
    token: ApiTokenRow;
    onRevoke: () => void;
}) {
    return (
        <li className="hover:bg-row-hover grid grid-cols-[minmax(0,1.6fr)_1.1fr_1.2fr_1fr_.8fr_90px] items-center gap-3 px-5 py-[11px] text-[13.5px]">
            <span className="min-w-0">
                <span className="block truncate font-semibold">
                    {token.name}
                </span>
                <span
                    className="text-muted-foreground block truncate text-[12px]"
                    title={token.abilities.map((a) => a.label).join(', ')}
                >
                    {token.abilities.map((a) => a.value).join(' · ')}
                </span>
                {token.subscriptions > 0 && (
                    <span className="text-primary block text-[12px]">
                        {plural(
                            token.subscriptions,
                            'assinatura de webhook',
                            'assinaturas de webhook',
                        )}
                    </span>
                )}
            </span>
            <code className="text-text-secondary font-mono text-[12.5px]">
                {token.prefix ?? '—'}
            </code>
            <span className="text-text-secondary text-[12.5px]">
                {formatDateMedium(token.created_at)}
                {token.created_by && ` · ${token.created_by}`}
                {token.expires_at && token.state === 'active' && (
                    <span className="text-muted-foreground block text-[12px]">
                        vale até {formatDateMedium(token.expires_at)}
                    </span>
                )}
            </span>
            <span className="text-text-secondary text-[12.5px]">
                {token.last_used_at
                    ? formatRelativeDateTime(token.last_used_at)
                    : 'Nunca usada'}
            </span>
            <span>
                <TokenStateBadge state={token.state} />
            </span>
            <span className="flex justify-end">
                {token.state !== 'revoked' && (
                    <Button
                        type="button"
                        variant="outline"
                        size="xxs"
                        className="hover:border-danger-border hover:bg-danger-bg hover:text-danger"
                        onClick={onRevoke}
                    >
                        Revogar
                    </Button>
                )}
            </span>
        </li>
    );
}
