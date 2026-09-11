import { router, usePage } from '@inertiajs/react';
import { ImageUp, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { AvatarInitials } from '@/components/avatar-initials';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { BrandingLimits, BrandingLogo } from '@/components/branding/types';
import {
    destroy as destroyLogo,
    store as storeLogo,
} from '@/routes/settings/branding/logo';

/**
 * Envio do logo da organização (Configurações › Marca; também serve para o
 * cartão "Logo da empresa" de Configurações › Geral).
 *
 * O arquivo vai como está: formato real, SVG, dimensões e metadados são
 * decididos no servidor (GD), que devolve o erro no campo `logo`.
 */
export function LogoUploadField({
    logo,
    initials,
    displayName,
    limits,
    disabled = false,
}: {
    logo: BrandingLogo | null;
    initials: string;
    displayName: string;
    limits: Pick<
        BrandingLimits,
        | 'logo_max_kb'
        | 'logo_formats'
        | 'logo_min_side'
        | 'logo_max_source_side'
    >;
    disabled?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }

        setBusy(true);
        router.post(
            storeLogo.url(),
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    const [confirmingRemoval, setConfirmingRemoval] = useState(false);

    const remove = () => {
        setBusy(true);
        router.delete(destroyLogo.url(), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setConfirmingRemoval(false);
            },
        });
    };

    return (
        <div className="grid gap-1.5">
            <div className="border-border flex flex-wrap items-center gap-3.5 rounded-[10px] border p-3.5">
                {logo?.url ? (
                    <div className="border-border flex h-14 w-28 shrink-0 items-center justify-center rounded-lg border bg-white p-1.5">
                        <img
                            src={logo.url}
                            alt={`Logo de ${displayName}`}
                            className="max-h-full max-w-full object-contain"
                        />
                    </div>
                ) : (
                    <AvatarInitials
                        initials={initials}
                        tone="organization"
                        size="2xl"
                    />
                )}
                <div className="min-w-0 flex-1">
                    <div className="text-[13.5px] font-semibold">
                        Logo da empresa
                    </div>
                    <div className="text-muted-foreground text-[12.5px] leading-[1.5]">
                        {limits.logo_formats.join(' ou ')}, até{' '}
                        {limits.logo_max_kb.toLocaleString('pt-BR')} KB, de{' '}
                        {limits.logo_min_side} a {limits.logo_max_source_side}{' '}
                        px no maior lado. SVG não é aceito. A imagem é
                        reprocessada e os metadados são descartados. Usado nos
                        e-mails, na página de assinatura e no carimbo visual.
                    </div>
                    {logo && (
                        <div className="text-muted-foreground mt-1 text-[11.5px]">
                            Atual: {logo.width}×{logo.height} px ·{' '}
                            {Math.max(1, Math.round(logo.bytes / 1024))} KB
                        </div>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <input
                        ref={input}
                        type="file"
                        accept="image/png,image/jpeg"
                        className="hidden"
                        onChange={(event) => upload(event.target.files?.[0])}
                        disabled={disabled || busy}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="xs"
                        onClick={() => input.current?.click()}
                        disabled={disabled || busy}
                    >
                        {busy ? <Spinner /> : <ImageUp />}
                        {logo ? 'Trocar logo' : 'Enviar logo'}
                    </Button>
                    {logo && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="xs"
                            onClick={() => setConfirmingRemoval(true)}
                            disabled={disabled || busy}
                            aria-label="Remover logo"
                        >
                            <Trash2 />
                        </Button>
                    )}
                </div>
            </div>
            <InputError message={errors.logo} />
            <ConfirmDialog
                open={confirmingRemoval}
                onOpenChange={(open) => !busy && setConfirmingRemoval(open)}
                destructive
                processing={busy}
                title="Remover o logo?"
                description="O logo deixa de aparecer nos e-mails, na página de assinatura, na página de evidências e no carimbo visual. O arquivo é apagado: para voltar a usá-lo, será preciso enviá-lo de novo."
                confirmLabel="Remover logo"
                onConfirm={remove}
            />
        </div>
    );
}
