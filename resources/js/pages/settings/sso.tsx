import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ConnectionForm } from '@/components/sso/connection-form';
import { DomainsCard } from '@/components/sso/domains-card';
import type {
    SettingsSsoProps,
    SsoConnectionProps,
} from '@/components/sso/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import {
    general as settingsGeneral,
    sso as settingsSso,
} from '@/routes/settings';
import {
    destroy,
    metadata,
    status as setStatus,
    test,
    update,
} from '@/routes/settings/sso/connections';

function CopyLine({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <span className="text-muted-foreground text-[12px]">{label}</span>
            <span className="flex min-w-0 items-center gap-1.5">
                <code className="truncate font-mono text-[12.5px]">
                    {value}
                </code>
                <CopyButton value={value} />
            </span>
        </div>
    );
}

function statusVariant(status: SsoConnectionProps['status']) {
    if (status === 'active') {
        return 'success' as const;
    }

    return status === 'disabled' ? ('neutral' as const) : ('draft' as const);
}

function ConnectionStatusCard({
    connection,
    canManage,
}: {
    connection: SsoConnectionProps;
    canManage: boolean;
}) {
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Record<string, string>;
    const [processing, setProcessing] = useState<string | null>(null);
    const [removeOpen, setRemoveOpen] = useState(false);
    const [disableOpen, setDisableOpen] = useState(false);
    const passed = connection.last_test.status === 'ok';
    // OIDC sem client secret: o teste falharia no provedor — melhor dizer antes.
    const missingSecret =
        connection.oidc !== null && !connection.oidc.has_client_secret;

    const post = (
        key: string,
        url: string,
        data: Record<string, string> = {},
    ) => {
        setProcessing(key);
        router.post(url, data, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    };

    const disable = () =>
        post('disable', setStatus.url(connection.ulid), {
            status: 'disabled',
        });

    return (
        <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
            <Heading
                variant="small"
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {connection.name}
                        <Badge variant={statusVariant(connection.status)}>
                            {connection.status_label}
                        </Badge>
                        <Badge variant="outline">
                            {connection.protocol_label}
                        </Badge>
                    </span>
                }
                description={
                    connection.last_login_at
                        ? `Última entrada pelo login corporativo: ${formatDateTime(connection.last_login_at)}.`
                        : 'Ninguém entrou por esta conexão ainda.'
                }
            />

            <div
                className={
                    connection.last_test.status === 'failed'
                        ? 'border-danger-border bg-danger-bg text-danger rounded-[10px] border p-3 text-[13px]'
                        : passed
                          ? 'border-success-border bg-success-bg text-success rounded-[10px] border p-3 text-[13px]'
                          : 'border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[13px]'
                }
                role="status"
            >
                {missingSecret
                    ? 'Falta o client secret. Preencha no formulário do provedor abaixo antes de testar a conexão.'
                    : connection.last_test.status === null
                      ? 'Conexão ainda não testada. O teste leva você ao provedor e confere assinatura, emissor, audiência, validade e o domínio do e-mail — sem entrar nem criar nada.'
                      : `${connection.last_test.message ?? ''} (${formatDateTime(connection.last_test.tested_at)})`}
            </div>
            <InputError message={errors.test ?? errors.status} />

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={
                        processing !== null ||
                        !connection.feature_enabled ||
                        missingSecret
                    }
                    title={
                        missingSecret
                            ? 'Falta o client secret: preencha antes de testar'
                            : undefined
                    }
                    onClick={() => post('test', test.url(connection.ulid))}
                >
                    {processing === 'test' && <Spinner />}
                    Testar conexão
                </Button>
                {connection.status !== 'active' ? (
                    <Button
                        type="button"
                        size="sm"
                        disabled={processing !== null || !passed || !canManage}
                        title={
                            !canManage
                                ? 'Só o proprietário da conta ativa o login corporativo'
                                : passed
                                  ? undefined
                                  : 'Teste a conexão com sucesso antes de ativar'
                        }
                        onClick={() =>
                            post('activate', setStatus.url(connection.ulid), {
                                status: 'active',
                            })
                        }
                    >
                        {processing === 'activate' && <Spinner />}
                        Ativar
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={processing !== null}
                        onClick={() =>
                            connection.enforce
                                ? setDisableOpen(true)
                                : disable()
                        }
                    >
                        {processing === 'disable' && <Spinner />}
                        Desativar
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setRemoveOpen(true)}
                >
                    Remover conexão
                </Button>
            </div>

            {connection.oidc && (
                <div className="border-muted flex flex-col gap-2 border-t pt-4">
                    <span className="text-[13px] font-semibold">
                        Cadastre no provedor
                    </span>
                    <CopyLine
                        label="URL de retorno (redirect URI)"
                        value={connection.oidc.redirect_uri}
                    />
                </div>
            )}

            {connection.saml && (
                <div className="border-muted flex flex-col gap-2 border-t pt-4">
                    <span className="text-[13px] font-semibold">
                        Cadastre no provedor (Service Provider)
                    </span>
                    <CopyLine
                        label="EntityID / metadata do SP"
                        value={connection.saml.sp_entity_id}
                    />
                    <CopyLine
                        label="ACS (HTTP-POST)"
                        value={connection.saml.sp_acs_url}
                    />
                    {connection.saml.certificates.length > 0 && (
                        <div className="flex flex-col gap-1 text-[12.5px]">
                            <span className="text-muted-foreground">
                                Certificados do provedor
                            </span>
                            {connection.saml.certificates.map((certificate) => (
                                <span
                                    key={certificate.fingerprint_sha256}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span className="font-medium">
                                        {certificate.subject || 'Sem nome'}
                                    </span>
                                    <code className="text-muted-foreground truncate font-mono text-[11.5px]">
                                        {certificate.fingerprint_sha256.slice(
                                            0,
                                            29,
                                        )}
                                        …
                                    </code>
                                    {certificate.expired ? (
                                        <Badge variant="danger">Vencido</Badge>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            vence em{' '}
                                            {formatDateTime(
                                                certificate.expires_at,
                                            )}
                                        </span>
                                    )}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            )}

            <ConfirmDialog
                open={disableOpen}
                onOpenChange={setDisableOpen}
                destructive
                title="Desativar o login corporativo"
                description="O login corporativo obrigatório cai junto: operadores e administradores voltam a entrar com e-mail e senha na hora. A desativação fica registrada na trilha."
                confirmLabel="Desativar"
                onConfirm={() => {
                    setDisableOpen(false);
                    disable();
                }}
            />

            <ConfirmDialog
                open={removeOpen}
                onOpenChange={setRemoveOpen}
                destructive
                title="Remover conexão"
                description="Todos voltam a entrar com e-mail e senha — inclusive onde o login corporativo era obrigatório. Os vínculos com o provedor são apagados; as contas e os acessos continuam."
                confirmLabel="Remover conexão"
                onConfirm={() =>
                    router.delete(destroy.url(connection.ulid), {
                        preserveScroll: true,
                        onFinish: () => setRemoveOpen(false),
                    })
                }
            />
        </section>
    );
}

function EnforcementCard({
    connection,
    canManage,
    twoFactorEnabled,
}: {
    connection: SsoConnectionProps;
    canManage: boolean;
    twoFactorEnabled: boolean;
}) {
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Record<string, string>;
    const [saving, setSaving] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    // Ligar exige o 2FA de quem liga (o acesso de emergência); desligar, nunca.
    const blockedOn = !connection.enforce && !twoFactorEnabled;
    const disabled =
        saving || !canManage || connection.status !== 'active' || blockedOn;

    const save = (enforce: boolean) => {
        setSaving(true);
        router.patch(
            update.url(connection.ulid),
            { enforce },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <section className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
            <div className="flex items-start justify-between gap-4">
                <Heading
                    variant="small"
                    title="Login corporativo obrigatório"
                    description="Operadores e administradores só acessam esta organização pelo provedor de identidade. Proprietários mantêm o acesso de emergência por senha com autenticação em duas etapas; cada uso fica registrado e avisa os demais administradores."
                />
                <Switch
                    checked={connection.enforce}
                    disabled={disabled}
                    aria-label="Login corporativo obrigatório"
                    onCheckedChange={(checked) =>
                        checked ? setConfirmOpen(true) : save(false)
                    }
                />
            </div>
            <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                {!canManage
                    ? 'Só o proprietário liga a obrigatoriedade. Desativar ou remover a conexão a suspende na hora, e operadores e administradores voltam a entrar por senha.'
                    : connection.status !== 'active'
                      ? 'Disponível com a conexão ativa.'
                      : blockedOn
                        ? 'Ative a autenticação em duas etapas na sua conta antes: com a obrigatoriedade, ela é o seu acesso de emergência por senha.'
                        : 'Desativar ou remover a conexão suspende a obrigatoriedade na hora: a organização nunca fica trancada.'}
            </p>
            <InputError message={errors.enforce} />

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Tornar o login corporativo obrigatório"
                description="Operadores e administradores que entraram por senha saem desta organização na próxima página e passam a entrar só pelo provedor de identidade. Você, como proprietário, mantém o acesso de emergência por senha com a autenticação em duas etapas; cada uso fica registrado e avisa os demais administradores."
                confirmLabel="Tornar obrigatório"
                onConfirm={() => {
                    setConfirmOpen(false);
                    save(true);
                }}
            />
        </section>
    );
}

function MetadataImportCard({
    connection,
}: {
    connection: SsoConnectionProps;
}) {
    const form = useForm({
        metadata_url: connection.saml?.metadata_url ?? '',
        metadata_xml: '',
    });
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Record<string, string>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(metadata.url(connection.ulid), { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5"
        >
            <Heading
                variant="small"
                title="Importar metadata do provedor"
                description="Preenche entityID, URL de login e certificados. A busca pela URL passa pela proteção contra endereços internos."
            />
            <Input
                aria-label="URL do metadata"
                value={form.data.metadata_url}
                placeholder="https://idp.suaempresa.com.br/saml/metadata"
                onChange={(e) => form.setData('metadata_url', e.target.value)}
            />
            <Textarea
                aria-label="XML do metadata"
                rows={3}
                className="font-mono text-[12px]"
                placeholder="…ou cole o XML do metadata"
                value={form.data.metadata_xml}
                onChange={(e) => form.setData('metadata_xml', e.target.value)}
            />
            <InputError
                message={
                    errors.metadata ??
                    form.errors.metadata_url ??
                    form.errors.metadata_xml
                }
            />
            <div className="flex justify-end">
                <Button
                    type="submit"
                    variant="outline"
                    disabled={form.processing}
                >
                    {form.processing && <Spinner />}
                    Importar
                </Button>
            </div>
        </form>
    );
}

/**
 * Configurações › Login único (SSO) — Fase 3 §3.9 (docs/fase-3/sso.md §10). Autentica a equipe
 * do painel; nada muda para quem assina documentos. O provedor, a política de 2FA, o JIT, o
 * login iniciado pelo provedor, ativar e a obrigatoriedade são decisões do proprietário; o
 * administrador vê, testa, verifica domínios, desativa e remove (nunca tranca a organização).
 */
export default function SettingsSso({
    enabled,
    homologated,
    can,
    connection,
    domains,
    limits,
    algorithms,
}: SettingsSsoProps) {
    return (
        <>
            <Head title="Configurações · Login único (SSO)" />

            <section className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
                <Heading
                    title="Login único (SSO)"
                    description="Sua equipe entra no painel pelo provedor de identidade da empresa, por OpenID Connect ou SAML 2.0. Não muda nada para quem assina documentos."
                />
                {!homologated && (
                    <div
                        className="border-warning-border bg-warning-bg text-warning rounded-[10px] border p-3 text-[13px] leading-[1.5]"
                        role="note"
                    >
                        <b>Em homologação.</b> O login corporativo foi testado
                        com provedores simulados e ainda não foi validado com um
                        provedor de identidade real. Use “Testar conexão” antes
                        de ativar.
                    </div>
                )}
                {!connection && (
                    <p className="text-muted-foreground text-[13px]">
                        Ainda não disponível para esta organização: nenhum
                        provedor de identidade foi conectado.
                    </p>
                )}
            </section>

            {connection && (
                <ConnectionStatusCard
                    connection={connection}
                    canManage={can.manage_connection}
                />
            )}

            <DomainsCard domains={domains} maxDomains={limits.max_domains} />

            <ConnectionForm
                key={connection?.ulid ?? 'new'}
                connection={connection}
                enabled={enabled}
                algorithms={algorithms}
                canManage={can.manage_connection}
            />

            {connection?.saml && can.manage_connection && (
                <MetadataImportCard connection={connection} />
            )}

            {connection && (
                <EnforcementCard
                    connection={connection}
                    canManage={can.manage_enforce}
                    twoFactorEnabled={can.two_factor_enabled}
                />
            )}
        </>
    );
}

SettingsSso.layout = {
    breadcrumbs: [
        { title: 'Configurações', href: settingsGeneral() },
        { title: 'Login único (SSO)', href: settingsSso() },
    ],
};
