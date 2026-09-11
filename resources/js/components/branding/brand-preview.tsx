import { FileText } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { AvatarInitials } from '@/components/avatar-initials';

/**
 * Prévia da marca: cabeçalho da página pública e botão do e-mail, com as
 * cores e o logo escolhidos. "via AssinaVelox" aparece sempre — a operadora
 * não some (whitelabel é backlog).
 */
export function BrandPreview({
    displayName,
    logoUrl,
    initials,
    primary,
    accent,
}: {
    displayName: string;
    logoUrl: string | null;
    initials: string;
    primary: string;
    accent: string;
}) {
    return (
        <div className="grid gap-3">
            <div className="border-border overflow-hidden rounded-[10px] border">
                <div
                    className="flex min-h-[52px] items-center gap-2.5 border-t-[3px] bg-white px-3"
                    style={{ borderTopColor: accent }}
                >
                    {logoUrl ? (
                        <img
                            src={logoUrl}
                            alt=""
                            className="h-7 max-w-[96px] object-contain"
                        />
                    ) : (
                        <AvatarInitials
                            initials={initials}
                            tone="organization"
                            size="sm"
                        />
                    )}
                    <span className="min-w-0">
                        <span className="block truncate text-[12.5px] font-semibold">
                            {displayName}
                        </span>
                        <span className="text-muted-foreground inline-flex items-center gap-1 text-[11px]">
                            <FileText className="size-3" /> Contrato de locação
                        </span>
                    </span>
                    <span className="text-muted-foreground border-border ml-auto flex items-center gap-1 border-l pl-2 text-[10.5px]">
                        via <AppLogo height={14} />
                    </span>
                </div>
                <div className="bg-accent flex items-center justify-center px-3 py-4">
                    <span
                        className="rounded-md px-4 py-2 text-[12.5px] font-semibold"
                        style={{ backgroundColor: primary, color: '#FFFFFF' }}
                    >
                        Revisar e assinar
                    </span>
                </div>
            </div>
            <p className="text-muted-foreground text-[11.5px] leading-[1.5]">
                Prévia do cabeçalho da página de assinatura e do botão dos
                e-mails. O rodapé “via AssinaVelox” e o link de verificação
                continuam em todas as páginas e mensagens.
            </p>
        </div>
    );
}
