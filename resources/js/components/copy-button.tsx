import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

/** Botão "Copiar" com feedback (ícone check por 1,5 s + toast "Copiado"). */
export function CopyButton({
    value,
    label,
    size = 'icon-xs',
    variant = 'ghost',
    className,
    toastMessage = 'Copiado',
}: {
    value: string;
    label?: string;
    size?: 'icon-xs' | 'icon-sm' | 'xxs' | 'xs';
    variant?: 'ghost' | 'outline' | 'outline-sm';
    className?: string;
    toastMessage?: string | null;
}) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), 1500);

        return () => window.clearTimeout(timer);
    }, [copied]);

    const handleCopy = async () => {
        const ok = await copy(value);

        if (ok) {
            setCopied(true);

            if (toastMessage) {
                toast.success(toastMessage);
            }
        } else {
            toast.error('Não foi possível copiar');
        }
    };

    const Icon = copied ? Check : Copy;

    return (
        <Button
            type="button"
            variant={variant}
            size={size}
            onClick={handleCopy}
            aria-label={label ?? 'Copiar'}
            title={label ?? 'Copiar'}
            className={cn(copied && 'text-success', className)}
        >
            <Icon className="size-3.5" />
            {label && !size.startsWith('icon') && <span>{label}</span>}
        </Button>
    );
}
