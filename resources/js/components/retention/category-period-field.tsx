import { ChevronDown } from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { describeDays, type RetentionCategory } from './types';

/**
 * Um prazo da política de retenção: campo em dias (vazio = não apagar
 * automaticamente), o mínimo da operadora e a lista fechada do que é apagado
 * e do que é preservado nesta categoria.
 */
export function CategoryPeriodField({
    category,
    value,
    saved,
    maxDays,
    disabled,
    error,
    onChange,
}: {
    category: RetentionCategory;
    value: string;
    saved: number | null;
    maxDays: number;
    disabled?: boolean;
    error?: string;
    onChange: (value: string) => void;
}) {
    const id = `period-${category.key}`;
    const parsed = value.trim() === '' ? null : Number(value);
    const reduced =
        parsed !== null &&
        Number.isFinite(parsed) &&
        (saved === null || parsed < saved);
    const unavailable = !category.available;

    return (
        <div className="border-border flex flex-col gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-[1_1_260px]">
                    <Label htmlFor={id} className="text-[14px] font-semibold">
                        {category.label}
                    </Label>
                    <p className="text-muted-foreground mt-0.5 text-[12.5px]">
                        {category.reference}
                    </p>
                </div>

                <div className="flex w-[220px] flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <Input
                            id={id}
                            type="number"
                            inputMode="numeric"
                            min={category.minimum_days}
                            max={maxDays}
                            step={1}
                            value={value}
                            onChange={(event) => onChange(event.target.value)}
                            placeholder="Não apagar"
                            disabled={disabled || unavailable}
                            aria-invalid={!!error}
                            aria-describedby={`${id}-help`}
                        />
                        <span className="text-muted-foreground text-[13px]">
                            dias
                        </span>
                    </div>
                    <p
                        id={`${id}-help`}
                        className="text-muted-foreground text-[12px]"
                    >
                        {parsed !== null &&
                        Number.isFinite(parsed) &&
                        parsed > 0
                            ? `Apagar depois de ${describeDays(parsed)}.`
                            : 'Vazio: nunca apagar automaticamente.'}{' '}
                        Mínimo: {describeDays(category.minimum_days)}.
                    </p>
                    {reduced && !unavailable && (
                        <Badge variant="warning" className="mt-0.5">
                            {saved === null
                                ? 'Passa a apagar'
                                : 'Prazo reduzido'}
                        </Badge>
                    )}
                </div>
            </div>

            {unavailable && category.unavailable_reason && (
                <p className="bg-muted text-muted-foreground rounded-md px-3 py-2 text-[12.5px]">
                    {category.unavailable_reason}
                </p>
            )}

            <InputError message={error} />

            <details className="group">
                <summary className="text-primary flex cursor-pointer list-none items-center gap-1 text-[12.5px] font-semibold">
                    <ChevronDown
                        aria-hidden
                        className="size-3.5 transition-transform group-open:rotate-180"
                    />
                    O que é apagado e o que fica
                </summary>
                <div className="mt-2 grid gap-3 text-[12.5px] sm:grid-cols-2">
                    <div>
                        <p className="text-danger mb-1 font-semibold">
                            Apagado
                        </p>
                        <ul className="text-muted-foreground list-disc space-y-1 pl-4">
                            {category.deletes.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </div>
                    <div>
                        <p className="text-success mb-1 font-semibold">
                            Preservado
                        </p>
                        <ul className="text-muted-foreground list-disc space-y-1 pl-4">
                            {category.preserves.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </div>
                </div>
            </details>
        </div>
    );
}
