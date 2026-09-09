import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AvatarInitials } from '@/components/avatar-initials';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { useConfirmsPassword } from '@/components/confirms-password';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { formatCpfCnpj, formatDate } from '@/lib/format';
import { general as settingsGeneral } from '@/routes/settings';
import {
    destroy as requestDeletion,
    update as updateOrganization,
} from '@/routes/settings/organization';
import { update as updateSecurity } from '@/routes/settings/security';

export interface SettingsGeneralProps {
    organization: {
        legal_name: string | null;
        name: string;
        tax_id: string | null;
        contact_email: string | null;
        initials: string;
        logo_url: string | null;
    };
    security: {
        require_two_factor: boolean;
        session_idle_hours: 12 | null;
        sso_enabled: boolean;
        ip_allowlist_enabled: boolean;
    };
    deletion: { requested_at: string | null; scheduled_for: string | null };
    can: { delete_organization: boolean };
}

function SecurityRow({
    title,
    description,
    checked,
    onCheckedChange,
    disabled,
    phase2,
}: {
    title: string;
    description: string;
    checked: boolean;
    onCheckedChange?: (checked: boolean) => void;
    disabled?: boolean;
    phase2?: boolean;
}) {
    return (
        <div className="border-muted flex items-center justify-between gap-4 border-t py-3 first:border-t-0">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold">
                    {title}
                    {phase2 && <Badge variant="phase">Fase 2</Badge>}
                </div>
                <div className="text-muted-foreground mt-0.5 text-[12.5px]">
                    {description}
                </div>
            </div>
            <Switch
                checked={checked}
                onCheckedChange={onCheckedChange}
                disabled={disabled}
                className="data-[state=unchecked]:bg-border-dashed h-[22px] w-10 [&>span]:size-[18px] [&>span]:data-[state=checked]:translate-x-[18px]"
            />
        </div>
    );
}

/** Configurações › Geral (ROUTES §2.12; DESIGN §6.11 "Geral e segurança"). */
export default function SettingsGeneral({
    organization,
    security,
    deletion,
    can,
}: SettingsGeneralProps) {
    const company = useForm({
        legal_name: organization.legal_name ?? '',
        name: organization.name,
        tax_id: organization.tax_id ?? '',
        contact_email: organization.contact_email ?? '',
    });

    const [requireTwoFactor, setRequireTwoFactor] = useState(
        security.require_two_factor,
    );
    const [sessionIdle, setSessionIdle] = useState(
        security.session_idle_hours === 12,
    );
    const [savingSecurity, setSavingSecurity] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const password = useConfirmsPassword({
        description:
            'Solicitar a exclusão da conta é uma ação protegida. Confirme sua senha para continuar.',
    });
    const [deleting, setDeleting] = useState(false);

    const saveCompany = (event: FormEvent) => {
        event.preventDefault();
        company.patch(updateOrganization.url(), { preserveScroll: true });
    };

    const saveSecurity = (next: {
        require_two_factor?: boolean;
        session_idle_hours?: 12 | null;
    }) => {
        setSavingSecurity(true);
        router.patch(
            updateSecurity.url(),
            {
                require_two_factor: next.require_two_factor ?? requireTwoFactor,
                session_idle_hours:
                    next.session_idle_hours ?? (sessionIdle ? 12 : null),
            },
            { preserveScroll: true, onFinish: () => setSavingSecurity(false) },
        );
    };

    /*
     * `settings.organization.destroy` está protegida por `password.confirm` (ROUTES §2.13).
     * Num POST o middleware não retoma a ação depois da confirmação — ele guarda o
     * *referer*, não a rota do POST —, então a senha é pedida antes do envio.
     */
    const requestDelete = () => {
        password.ensure(() => {
            setDeleting(true);
            router.post(
                requestDeletion.url(),
                {},
                {
                    preserveScroll: true,
                    onFinish: () => {
                        setDeleting(false);
                        setDeleteOpen(false);
                    },
                },
            );
        });
    };

    const cancelDelete = () => {
        router.delete(requestDeletion.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Configurações · Geral" />

            <form
                onSubmit={saveCompany}
                className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5"
            >
                <Heading
                    variant="small"
                    title="Empresa"
                    description="Aparece nos convites, no certificado de conclusão e nos recibos."
                />
                <div
                    className="grid gap-3"
                    style={{
                        gridTemplateColumns:
                            'repeat(auto-fit, minmax(220px, 1fr))',
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="legal_name">Razão social</Label>
                        <Input
                            id="legal_name"
                            value={company.data.legal_name}
                            onChange={(e) =>
                                company.setData('legal_name', e.target.value)
                            }
                            placeholder="Horizonte Negócios Imobiliários Ltda."
                            aria-invalid={!!company.errors.legal_name}
                        />
                        <InputError message={company.errors.legal_name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="name">Nome de exibição</Label>
                        <Input
                            id="name"
                            value={company.data.name}
                            onChange={(e) =>
                                company.setData('name', e.target.value)
                            }
                            required
                            aria-invalid={!!company.errors.name}
                        />
                        <InputError message={company.errors.name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="tax_id">CNPJ ou CPF</Label>
                        <Input
                            id="tax_id"
                            inputMode="numeric"
                            className="tabular"
                            value={company.data.tax_id}
                            onChange={(e) =>
                                company.setData(
                                    'tax_id',
                                    formatCpfCnpj(e.target.value),
                                )
                            }
                            placeholder="00.000.000/0000-00"
                            aria-invalid={!!company.errors.tax_id}
                        />
                        <InputError message={company.errors.tax_id} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="contact_email">E-mail de contato</Label>
                        <Input
                            id="contact_email"
                            type="email"
                            value={company.data.contact_email}
                            onChange={(e) =>
                                company.setData('contact_email', e.target.value)
                            }
                            placeholder="contato@empresa.com.br"
                            aria-invalid={!!company.errors.contact_email}
                        />
                        <InputError message={company.errors.contact_email} />
                    </div>
                </div>

                <div className="border-border flex items-center gap-3.5 rounded-[10px] border p-3.5">
                    <AvatarInitials
                        initials={organization.initials}
                        tone="organization"
                        size="2xl"
                    />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold">
                            Logo da empresa{' '}
                            <Badge variant="phase">Fase 2</Badge>
                        </div>
                        <div className="text-muted-foreground text-[12.5px] leading-[1.5]">
                            PNG ou SVG, fundo transparente, mínimo 200×200.
                            Usado nos e-mails e na página de assinatura.
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="xs"
                        disabled
                        title="Disponível na Fase 2"
                    >
                        Enviar logo
                    </Button>
                </div>

                <div className="flex justify-end">
                    <Button
                        type="submit"
                        disabled={company.processing || !company.isDirty}
                    >
                        {company.processing && <Spinner />}
                        Salvar alterações
                    </Button>
                </div>
            </form>

            <div className="border-border bg-card shadow-card flex flex-col rounded-xl border p-5">
                <Heading
                    variant="small"
                    title="Segurança"
                    description="Políticas aplicadas a todos os usuários da conta."
                    action={
                        savingSecurity ? (
                            <Spinner className="text-muted-foreground size-4" />
                        ) : undefined
                    }
                    className="mb-2"
                />
                <SecurityRow
                    title="Exigir autenticação em duas etapas"
                    description="Todos os usuários precisam configurar 2FA no próximo acesso"
                    checked={requireTwoFactor}
                    disabled={savingSecurity}
                    onCheckedChange={(checked) => {
                        setRequireTwoFactor(checked);
                        saveSecurity({ require_two_factor: checked });
                    }}
                />
                <SecurityRow
                    title="Login único (SSO / SAML)"
                    description="Disponível no plano Empresarial"
                    checked={security.sso_enabled}
                    disabled
                    phase2
                />
                <SecurityRow
                    title="Encerrar sessões após 12 h inativas"
                    description="Recomendado para computadores compartilhados"
                    checked={sessionIdle}
                    disabled={savingSecurity}
                    onCheckedChange={(checked) => {
                        setSessionIdle(checked);
                        saveSecurity({
                            session_idle_hours: checked ? 12 : null,
                        });
                    }}
                />
                <SecurityRow
                    title="Restringir acesso por IP"
                    description="Somente a partir dos endereços da empresa"
                    checked={security.ip_allowlist_enabled}
                    disabled
                    phase2
                />
            </div>

            {can.delete_organization && (
                <div className="border-danger-border bg-card shadow-card flex flex-wrap items-center justify-between gap-4 rounded-xl border p-5">
                    <div className="min-w-0">
                        <h2 className="text-danger text-[15px] font-semibold">
                            Excluir conta
                        </h2>
                        {deletion.requested_at ? (
                            <p className="text-text-secondary mt-1 text-[13px] leading-[1.5]">
                                Exclusão agendada para{' '}
                                <b>{formatDate(deletion.scheduled_for)}</b>. Até
                                essa data você pode cancelar.
                            </p>
                        ) : (
                            <p className="text-muted-foreground mt-1 text-[13px] leading-[1.5]">
                                Remove todos os usuários e documentos após 30
                                dias. Documentos assinados continuam válidos
                                para quem os baixou.
                            </p>
                        )}
                    </div>
                    {deletion.requested_at ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={cancelDelete}
                        >
                            Cancelar exclusão
                        </Button>
                    ) : (
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => setDeleteOpen(true)}
                        >
                            Solicitar exclusão
                        </Button>
                    )}
                </div>
            )}

            <ConfirmDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                destructive
                processing={deleting}
                title="Solicitar exclusão da conta"
                description="A organização, seus usuários e documentos serão removidos em 30 dias. A assinatura do plano é cancelada. Você poderá cancelar a exclusão até a data agendada."
                confirmLabel="Solicitar exclusão"
                confirmText={organization.name}
                confirmTextLabel={
                    <>
                        Digite <b>{organization.name}</b> para confirmar
                    </>
                }
                onConfirm={requestDelete}
            />

            {password.dialog}
        </>
    );
}

SettingsGeneral.layout = {
    breadcrumbs: [{ title: 'Configurações', href: settingsGeneral() }],
};
