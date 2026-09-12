import { KeyRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';

/**
 * Banner de sucesso com um valor secreto exibido UMA única vez (chave de API ou
 * segredo de webhook) — DESIGN §4.18 "Success banner" do mock de Integrações.
 * O valor só existe no estado da página; recarregar não o traz de volta.
 */
export function OneTimeSecret({
    title,
    value,
    children,
    onDismiss,
}: {
    title: string;
    value: string;
    children?: ReactNode;
    onDismiss: () => void;
}) {
    return (
        <div
            role="status"
            className="bg-success-bg border-success-border flex flex-col gap-2 rounded-[10px] border px-4 py-3.5"
        >
            <div className="flex items-start justify-between gap-3">
                <p className="text-success flex items-center gap-2 text-[13.5px] font-semibold">
                    <KeyRound className="size-4 shrink-0" />
                    {title}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="xxs"
                    className="text-success hover:bg-white/60"
                    onClick={onDismiss}
                >
                    Fechar
                </Button>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <code
                    data-testid="one-time-secret"
                    className="border-success-border text-foreground min-w-0 flex-1 rounded-md border bg-white px-2.5 py-2 font-mono text-[13px] break-all"
                >
                    {value}
                </code>
                <CopyButton
                    value={value}
                    label="Copiar"
                    size="xs"
                    variant="outline"
                    className="text-success"
                    toastMessage="Copiado. Guarde em um cofre de senhas."
                />
            </div>
            <p className="text-success text-[12.5px]">
                Copie agora: ele não será exibido novamente. Se perder, crie
                outro e descarte este.
            </p>
            {children}
        </div>
    );
}
