import { CircleAlert, CircleCheck } from 'lucide-react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    WHITE,
    contrastRatio,
    formatRatio,
    normalizeHex,
} from '@/components/branding/contrast';
import { cn } from '@/lib/utils';

/**
 * Campo de cor da marca: seletor nativo + hexadecimal + contraste calculado
 * contra o branco. O aviso é só informativo — o servidor recusa o que ficar
 * abaixo do mínimo.
 */
export function ColorField({
    id,
    label,
    description,
    value,
    fallback,
    minimum,
    onChange,
    error,
    disabled = false,
}: {
    id: string;
    label: string;
    description: string;
    /** Valor digitado (pode estar vazio = padrão da plataforma). */
    value: string;
    /** Cor usada quando o campo está vazio. */
    fallback: string;
    /** Razão mínima exigida contra o branco. */
    minimum: number;
    onChange: (value: string) => void;
    error?: string;
    disabled?: boolean;
}) {
    const effective = normalizeHex(value) ?? fallback;
    const valid = value.trim() === '' || normalizeHex(value) !== null;
    const ratio = contrastRatio(effective, WHITE);
    const legible = ratio >= minimum;

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    aria-label={`${label}: seletor`}
                    value={effective.toLowerCase()}
                    onChange={(event) =>
                        onChange(event.target.value.toUpperCase())
                    }
                    disabled={disabled}
                    className="border-border size-9 shrink-0 cursor-pointer rounded-md border bg-white p-0.5 disabled:cursor-not-allowed"
                />
                <Input
                    id={id}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder={fallback}
                    maxLength={7}
                    spellCheck={false}
                    autoComplete="off"
                    className="font-mono uppercase"
                    aria-invalid={!!error || !valid}
                    disabled={disabled}
                />
            </div>
            <p className="text-muted-foreground text-[12px] leading-[1.5]">
                {description}
            </p>
            {valid && (
                <p
                    className={cn(
                        'flex items-center gap-1.5 text-[12px]',
                        legible ? 'text-success' : 'text-warning',
                    )}
                >
                    {legible ? (
                        <CircleCheck className="size-3.5" />
                    ) : (
                        <CircleAlert className="size-3.5" />
                    )}
                    Contraste com o branco: {formatRatio(ratio)} (mínimo{' '}
                    {formatRatio(minimum)})
                </p>
            )}
            <InputError
                message={
                    error ??
                    (valid
                        ? undefined
                        : 'Use o formato #RRGGBB (ex.: #1257C9).')
                }
            />
        </div>
    );
}
