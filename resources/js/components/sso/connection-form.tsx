import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/settings/sso/connections';
import type {
    SettingsSsoProps,
    SsoConnectionProps,
    SsoProtocol,
} from './types';

type Props = {
    connection: SsoConnectionProps | null;
    enabled: SettingsSsoProps['enabled'];
    algorithms: string[];
    /**
     * Owner: muda o provedor, a política de 2FA, o papel do JIT e o login iniciado pelo provedor.
     * Admin: só o nome e o JIT ligado/desligado (o servidor recusa o resto — revisão G).
     */
    canManage: boolean;
};

function Field({
    id,
    label,
    hint,
    error,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && (
                <p className="text-muted-foreground text-[12px] leading-[1.5]">
                    {hint}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

function ToggleRow({
    id,
    title,
    description,
    checked,
    onChange,
    disabled = false,
}: {
    id: string;
    title: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-start justify-between gap-4">
            <div className="min-w-0">
                <Label htmlFor={id} className="text-[13.5px] font-semibold">
                    {title}
                </Label>
                <p className="text-muted-foreground mt-0.5 text-[12.5px] leading-[1.5]">
                    {description}
                </p>
            </div>
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onChange}
                disabled={disabled}
            />
        </div>
    );
}

function secretHint(connection: SsoConnectionProps | null): string {
    if (connection?.oidc?.has_client_secret) {
        return 'Guardado cifrado. Deixe em branco para manter o atual.';
    }

    return connection
        ? 'Obrigatório para testar a conexão. Guardado cifrado; nunca é exibido de novo.'
        : 'Guardado cifrado; nunca é exibido de novo.';
}

/**
 * Cadastro/edição da conexão de login corporativo (docs/fase-3/sso.md §3 e §10). Trocar
 * qualquer dado do provedor volta a conexão para "Em configuração" e exige novo teste.
 */
export function ConnectionForm({
    connection,
    enabled,
    algorithms,
    canManage,
}: Props) {
    const initialProtocol: SsoProtocol =
        connection?.protocol ?? (enabled.oidc ? 'oidc' : 'saml');
    // Campos que só o owner muda; sem conexão, o admin não conecta o provedor.
    const locked = !canManage;
    const readOnly = locked && !connection;

    const form = useForm({
        protocol: initialProtocol,
        name: connection?.name ?? '',
        oidc_issuer: connection?.oidc?.issuer ?? '',
        oidc_client_id: connection?.oidc?.client_id ?? '',
        oidc_client_secret: '',
        oidc_id_token_alg: connection?.oidc?.id_token_alg ?? 'RS256',
        saml_idp_entity_id: connection?.saml?.idp_entity_id ?? '',
        saml_idp_sso_url: connection?.saml?.idp_sso_url ?? '',
        saml_idp_certificates: '',
        saml_allow_idp_initiated:
            connection?.saml?.allow_idp_initiated ?? false,
        jit_provisioning: connection?.jit_provisioning ?? false,
        jit_role: connection?.jit_role ?? 'member',
        two_factor_policy: connection?.two_factor_policy ?? 'keep',
    });

    const isOidc = form.data.protocol === 'oidc';

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (connection) {
            form.patch(update.url(connection.ulid), {
                preserveScroll: true,
                onSuccess: () =>
                    form.reset('oidc_client_secret', 'saml_idp_certificates'),
            });
        } else {
            form.post(store.url(), {
                preserveScroll: true,
                onSuccess: () =>
                    form.reset('oidc_client_secret', 'saml_idp_certificates'),
            });
        }
    };

    return (
        <form
            onSubmit={submit}
            className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5"
            aria-label="Conexão de login corporativo"
        >
            <Heading
                variant="small"
                title={
                    connection
                        ? 'Provedor de identidade'
                        : 'Conectar provedor de identidade'
                }
                description="Dados fornecidos pelo provedor da sua empresa (Microsoft Entra ID, Google Workspace, Okta, Keycloak, ADFS…)."
            />

            {locked && (
                <p
                    className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]"
                    role="note"
                >
                    {connection
                        ? 'Só o proprietário da conta muda o provedor, a autenticação em duas etapas de quem entra pelo SSO, o papel dado no primeiro acesso e o login iniciado pelo provedor. Você pode mudar o nome da conexão e ligar ou desligar a criação de acesso no primeiro login.'
                        : 'Só o proprietário da conta conecta o provedor de identidade.'}
                </p>
            )}

            {!connection && (
                <div className="grid gap-1.5">
                    <Label>Protocolo</Label>
                    <RadioGroup
                        value={form.data.protocol}
                        onValueChange={(value) =>
                            form.setData('protocol', value as SsoProtocol)
                        }
                        className="flex flex-wrap gap-4"
                        disabled={readOnly}
                    >
                        {enabled.oidc && (
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    value="oidc"
                                    id="protocol_oidc"
                                />
                                <Label htmlFor="protocol_oidc">
                                    OpenID Connect (OIDC)
                                </Label>
                            </div>
                        )}
                        {enabled.saml && (
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    value="saml"
                                    id="protocol_saml"
                                />
                                <Label htmlFor="protocol_saml">SAML 2.0</Label>
                            </div>
                        )}
                    </RadioGroup>
                    <InputError message={form.errors.protocol} />
                </div>
            )}

            <Field
                id="sso_name"
                label="Nome da conexão"
                error={form.errors.name}
            >
                <Input
                    id="sso_name"
                    value={form.data.name}
                    maxLength={120}
                    placeholder="Login da empresa"
                    disabled={readOnly}
                    onChange={(e) => form.setData('name', e.target.value)}
                    aria-invalid={!!form.errors.name}
                />
            </Field>

            {isOidc ? (
                <div className="grid gap-3">
                    <Field
                        id="oidc_issuer"
                        label="Emissor (issuer)"
                        hint="Endereço https:// do provedor. O discovery (/.well-known/openid-configuration) é lido dele."
                        error={form.errors.oidc_issuer}
                    >
                        <Input
                            id="oidc_issuer"
                            value={form.data.oidc_issuer}
                            placeholder="https://login.suaempresa.com.br"
                            disabled={locked}
                            onChange={(e) =>
                                form.setData('oidc_issuer', e.target.value)
                            }
                            aria-invalid={!!form.errors.oidc_issuer}
                        />
                    </Field>
                    <div
                        className="grid gap-3"
                        style={{
                            gridTemplateColumns:
                                'repeat(auto-fit, minmax(220px, 1fr))',
                        }}
                    >
                        <Field
                            id="oidc_client_id"
                            label="Client ID"
                            error={form.errors.oidc_client_id}
                        >
                            <Input
                                id="oidc_client_id"
                                value={form.data.oidc_client_id}
                                disabled={locked}
                                onChange={(e) =>
                                    form.setData(
                                        'oidc_client_id',
                                        e.target.value,
                                    )
                                }
                                aria-invalid={!!form.errors.oidc_client_id}
                            />
                        </Field>
                        <Field
                            id="oidc_client_secret"
                            label="Client secret"
                            hint={secretHint(connection)}
                            error={form.errors.oidc_client_secret}
                        >
                            <Input
                                id="oidc_client_secret"
                                type="password"
                                autoComplete="off"
                                value={form.data.oidc_client_secret}
                                disabled={locked}
                                onChange={(e) =>
                                    form.setData(
                                        'oidc_client_secret',
                                        e.target.value,
                                    )
                                }
                                aria-invalid={!!form.errors.oidc_client_secret}
                            />
                        </Field>
                    </div>
                    <Field
                        id="oidc_id_token_alg"
                        label="Algoritmo de assinatura do id_token"
                        hint="Fixado nesta conexão. Tokens com outro algoritmo são recusados."
                        error={form.errors.oidc_id_token_alg}
                    >
                        <Select
                            value={form.data.oidc_id_token_alg}
                            onValueChange={(value) =>
                                form.setData('oidc_id_token_alg', value)
                            }
                            disabled={locked}
                        >
                            <SelectTrigger
                                id="oidc_id_token_alg"
                                className="w-40"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {algorithms.map((alg) => (
                                    <SelectItem key={alg} value={alg}>
                                        {alg}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>
            ) : (
                <div className="grid gap-3">
                    <Field
                        id="saml_idp_entity_id"
                        label="EntityID do provedor"
                        error={form.errors.saml_idp_entity_id}
                    >
                        <Input
                            id="saml_idp_entity_id"
                            value={form.data.saml_idp_entity_id}
                            disabled={locked}
                            onChange={(e) =>
                                form.setData(
                                    'saml_idp_entity_id',
                                    e.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.saml_idp_entity_id}
                        />
                    </Field>
                    <Field
                        id="saml_idp_sso_url"
                        label="URL de login (SSO, HTTP-Redirect)"
                        error={form.errors.saml_idp_sso_url}
                    >
                        <Input
                            id="saml_idp_sso_url"
                            value={form.data.saml_idp_sso_url}
                            placeholder="https://idp.suaempresa.com.br/saml/sso"
                            disabled={locked}
                            onChange={(e) =>
                                form.setData('saml_idp_sso_url', e.target.value)
                            }
                            aria-invalid={!!form.errors.saml_idp_sso_url}
                        />
                    </Field>
                    <Field
                        id="saml_idp_certificates"
                        label="Certificado de assinatura do provedor (PEM)"
                        hint={
                            connection?.saml?.certificates.length
                                ? 'Deixe em branco para manter os certificados atuais. Para a rotação, cole o atual e o novo.'
                                : 'Cole o certificado público X.509. Nunca cole uma chave privada.'
                        }
                        error={form.errors.saml_idp_certificates}
                    >
                        <Textarea
                            id="saml_idp_certificates"
                            rows={5}
                            className="font-mono text-[12px]"
                            placeholder="-----BEGIN CERTIFICATE-----"
                            value={form.data.saml_idp_certificates}
                            disabled={locked}
                            onChange={(e) =>
                                form.setData(
                                    'saml_idp_certificates',
                                    e.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.saml_idp_certificates}
                        />
                    </Field>
                    <ToggleRow
                        id="saml_allow_idp_initiated"
                        title="Aceitar login iniciado pelo provedor"
                        description="Desligado por padrão: sem um pedido nosso, não há como provar que foi este navegador que pediu o login."
                        checked={form.data.saml_allow_idp_initiated}
                        disabled={locked}
                        onChange={(checked) =>
                            form.setData('saml_allow_idp_initiated', checked)
                        }
                    />
                </div>
            )}

            <div className="border-muted flex flex-col gap-4 border-t pt-4">
                <ToggleRow
                    id="jit_provisioning"
                    title="Criar acesso no primeiro login (JIT)"
                    description="Quem tem e-mail de um domínio verificado e ainda não participa da organização entra com o papel abaixo. Nunca como proprietário."
                    checked={form.data.jit_provisioning}
                    disabled={readOnly}
                    onChange={(checked) =>
                        form.setData('jit_provisioning', checked)
                    }
                />
                {form.data.jit_provisioning && (
                    <Field
                        id="jit_role"
                        label="Papel de quem entra pelo JIT"
                        error={form.errors.jit_role}
                    >
                        <Select
                            value={form.data.jit_role}
                            onValueChange={(value) =>
                                form.setData(
                                    'jit_role',
                                    value as 'member' | 'admin',
                                )
                            }
                            disabled={locked}
                        >
                            <SelectTrigger id="jit_role" className="w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="member">Operador</SelectItem>
                                <SelectItem value="admin">
                                    Administrador
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                )}

                <div className="grid gap-1.5">
                    <Label>
                        Autenticação em duas etapas de quem entra pelo SSO
                    </Label>
                    <RadioGroup
                        value={form.data.two_factor_policy}
                        onValueChange={(value) =>
                            form.setData(
                                'two_factor_policy',
                                value as 'keep' | 'trust_idp',
                            )
                        }
                        className="grid gap-2"
                        disabled={locked}
                    >
                        <div className="flex items-start gap-2">
                            <RadioGroupItem
                                value="keep"
                                id="tfa_keep"
                                className="mt-0.5"
                            />
                            <Label
                                htmlFor="tfa_keep"
                                className="leading-[1.5] font-normal"
                            >
                                Continua valendo: quem ativou o 2FA na conta
                                digita o código depois do provedor (recomendado)
                            </Label>
                        </div>
                        <div className="flex items-start gap-2">
                            <RadioGroupItem
                                value="trust_idp"
                                id="tfa_trust"
                                className="mt-0.5"
                            />
                            <Label
                                htmlFor="tfa_trust"
                                className="leading-[1.5] font-normal"
                            >
                                Dispensado nesta organização: ela confia no
                                segundo fator do próprio provedor (a decisão
                                fica registrada). Em outra organização da mesma
                                pessoa, o código continua sendo pedido.
                            </Label>
                        </div>
                    </RadioGroup>
                    <InputError message={form.errors.two_factor_policy} />
                </div>
            </div>

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing || readOnly}>
                    {form.processing && <Spinner />}
                    {connection ? 'Salvar alterações' : 'Salvar conexão'}
                </Button>
            </div>
        </form>
    );
}
