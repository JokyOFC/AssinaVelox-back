import { Head, useForm } from '@inertiajs/react';
import { Mail, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { BrandPreview } from '@/components/branding/brand-preview';
import { ColorField } from '@/components/branding/color-field';
import { normalizeHex } from '@/components/branding/contrast';
import { LogoUploadField } from '@/components/branding/logo-upload-field';
import { StampPreview } from '@/components/branding/stamp-preview';
import type { BrandingLimits, BrandingLogo } from '@/components/branding/types';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    branding as settingsBranding,
    general as settingsGeneral,
} from '@/routes/settings';
import { update as updateBranding } from '@/routes/settings/branding';

export interface SettingsBrandingProps {
    /** `features.branding` para a organização (config global E plano). */
    enabled: boolean;
    organization_name: string;
    organization_initials: string;
    branding: {
        display_name: string | null;
        primary_color: string | null;
        accent_color: string | null;
        reply_to_email: string | null;
        sender_email: string | null;
        logo: BrandingLogo | null;
        updated_at: string | null;
    };
    defaults: {
        display_name: string;
        primary_color: string;
        accent_color: string;
    };
    limits: BrandingLimits;
    sender: {
        platform_from_address: string;
        platform_from_name: string;
        custom_sender_active: boolean;
        domain: string | null;
        domain_verified: boolean;
        /** Situação do remetente próprio em linguagem de produto (sem pendências internas). */
        pending: string[];
    };
}

/**
 * Configurações › Marca (Fase 2 §2.8 — docs/fase-2/branding.md).
 *
 * Logo, nome de exibição e cores aparecem no cabeçalho da página pública do
 * signatário, nos e-mails aos participantes, no cabeçalho da página de
 * evidências e no carimbo visual. "via AssinaVelox" e o link de verificação
 * não são removíveis.
 */
export default function SettingsBranding({
    enabled,
    organization_name,
    organization_initials,
    branding,
    defaults,
    limits,
    sender,
}: SettingsBrandingProps) {
    const form = useForm({
        display_name: branding.display_name ?? '',
        primary_color: branding.primary_color ?? '',
        accent_color: branding.accent_color ?? '',
        reply_to_email: branding.reply_to_email ?? '',
        sender_email: branding.sender_email ?? '',
    });

    if (!enabled) {
        return (
            <>
                <Head title="Configurações · Marca" />
                <Phase2EmptyState
                    title="Marca da organização"
                    description="Logo, cores e e-mail para respostas nos e-mails aos participantes, na página de assinatura e na página de evidências. Disponível quando o recurso estiver ativo no seu plano."
                    ctaHref={settingsGeneral().url}
                    ctaLabel="Voltar para Configurações"
                />
            </>
        );
    }

    const displayName = form.data.display_name.trim() || defaults.display_name;
    const primary =
        normalizeHex(form.data.primary_color) ?? defaults.primary_color;
    const accent =
        normalizeHex(form.data.accent_color) ?? defaults.accent_color;
    const logoUrl = branding.logo?.url ?? null;

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(updateBranding.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Configurações · Marca" />

            <form
                onSubmit={save}
                className="flex flex-col gap-4"
                aria-label="Marca da organização"
            >
                <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Identidade visual"
                        description="Aparece no cabeçalho da página de assinatura, nos e-mails aos participantes e no cabeçalho da página de evidências."
                    />

                    <LogoUploadField
                        logo={branding.logo}
                        initials={organization_initials}
                        displayName={displayName}
                        limits={limits}
                    />

                    <div className="grid gap-1.5">
                        <Label htmlFor="display_name">Nome de exibição</Label>
                        <Input
                            id="display_name"
                            value={form.data.display_name}
                            onChange={(e) =>
                                form.setData('display_name', e.target.value)
                            }
                            placeholder={organization_name}
                            maxLength={limits.display_name_max}
                            aria-invalid={!!form.errors.display_name}
                        />
                        <p className="text-muted-foreground text-[12px]">
                            Vazio usa o nome da organização ({organization_name}
                            ).
                        </p>
                        <InputError message={form.errors.display_name} />
                    </div>
                </section>

                <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Cores"
                        description="Combinações ilegíveis são recusadas: o texto branco precisa de contraste mínimo sobre a cor primária."
                    />
                    <div
                        className="grid gap-4"
                        style={{
                            gridTemplateColumns:
                                'repeat(auto-fit, minmax(240px, 1fr))',
                        }}
                    >
                        <div className="grid content-start gap-4">
                            <ColorField
                                id="primary_color"
                                label="Cor primária"
                                description="Botões dos e-mails, com texto branco por cima."
                                value={form.data.primary_color}
                                fallback={defaults.primary_color}
                                minimum={limits.contrast.primary_min}
                                onChange={(value) =>
                                    form.setData('primary_color', value)
                                }
                                error={form.errors.primary_color}
                            />
                            <ColorField
                                id="accent_color"
                                label="Cor de destaque"
                                description="Faixa do cabeçalho e moldura do carimbo, sobre fundo branco."
                                value={form.data.accent_color}
                                fallback={defaults.accent_color}
                                minimum={limits.contrast.accent_min}
                                onChange={(value) =>
                                    form.setData('accent_color', value)
                                }
                                error={form.errors.accent_color}
                            />
                        </div>
                        <BrandPreview
                            displayName={displayName}
                            logoUrl={logoUrl}
                            initials={organization_initials}
                            primary={primary}
                            accent={accent}
                        />
                    </div>
                </section>

                <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="E-mails aos participantes"
                        description={
                            <>
                                Os e-mails saem de{' '}
                                <b>{sender.platform_from_address}</b>. Com um
                                endereço para respostas, quando o participante
                                responder, a mensagem vai para a sua
                                organização.
                            </>
                        }
                    />
                    <div className="grid gap-1.5">
                        <Label htmlFor="reply_to_email">
                            E-mail para respostas (Reply-To)
                        </Label>
                        <Input
                            id="reply_to_email"
                            type="email"
                            value={form.data.reply_to_email}
                            onChange={(e) =>
                                form.setData('reply_to_email', e.target.value)
                            }
                            placeholder="contato@empresa.com.br"
                            aria-invalid={!!form.errors.reply_to_email}
                        />
                        <InputError message={form.errors.reply_to_email} />
                    </div>

                    <div className="border-border grid gap-2 rounded-[10px] border p-3.5">
                        <div className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold">
                            <Mail className="size-4" />
                            Remetente próprio
                            {sender.custom_sender_active ? (
                                <Badge variant="success">
                                    <ShieldCheck /> Domínio verificado
                                </Badge>
                            ) : (
                                <Badge variant="outline">
                                    Domínio não verificado
                                </Badge>
                            )}
                        </div>
                        <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                            Os e-mails só saem com o seu endereço quando o
                            domínio dele estiver verificado no serviço de
                            e-mail. Até lá, continuam saindo de{' '}
                            {sender.platform_from_address}, com as respostas
                            indo para o endereço acima.
                        </p>
                        <div className="grid gap-1.5">
                            <Label htmlFor="sender_email">
                                Endereço desejado
                            </Label>
                            <Input
                                id="sender_email"
                                type="email"
                                value={form.data.sender_email}
                                onChange={(e) =>
                                    form.setData('sender_email', e.target.value)
                                }
                                placeholder="documentos@empresa.com.br"
                                aria-invalid={!!form.errors.sender_email}
                            />
                            <InputError message={form.errors.sender_email} />
                        </div>
                        {!sender.custom_sender_active && (
                            <div className="text-muted-foreground text-[12px] leading-[1.5]">
                                <div className="font-medium">
                                    Como funciona hoje:
                                </div>
                                <ul className="mt-1 list-disc pl-4">
                                    {sender.pending.map((item) => (
                                        <li key={item}>{item}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                </section>

                <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Carimbo visual"
                        description="No editor de campos, o tipo “Carimbo visual” posiciona o logo e o nome da organização no documento, por participante. No PDF final ele sai como imagem."
                    />
                    <StampPreview
                        displayName={displayName}
                        logoUrl={logoUrl}
                        initials={organization_initials}
                        primary={primary}
                        accent={accent}
                        caption
                    />
                </section>

                <div className="flex justify-end">
                    <Button
                        type="submit"
                        disabled={form.processing || !form.isDirty}
                    >
                        {form.processing && <Spinner />}
                        Salvar marca
                    </Button>
                </div>
            </form>
        </>
    );
}

SettingsBranding.layout = {
    breadcrumbs: [
        { title: 'Configurações', href: settingsGeneral() },
        { title: 'Marca', href: settingsBranding() },
    ],
};
