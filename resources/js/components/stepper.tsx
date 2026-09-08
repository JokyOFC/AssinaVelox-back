import { Check } from 'lucide-react';
import { Fragment } from 'react';
import { cn } from '@/lib/utils';

export type StepperStep = {
    key: string;
    title: string;
    subtitle?: string;
    disabled?: boolean;
};

/**
 * Stepper (DESIGN §4.12).
 * - `variant="wizard"`: horizontal em card branco, círculos 28px, conectores.
 * - `variant="pills"`: pills do header público (Confirmar identidade → Assinar → Concluído).
 */
export function Stepper({
    steps,
    current,
    onSelect,
    variant = 'wizard',
    className,
}: {
    steps: StepperStep[];
    current: number; // índice 0-based do passo atual
    onSelect?: (index: number) => void;
    variant?: 'wizard' | 'pills';
    className?: string;
}) {
    if (variant === 'pills') {
        return (
            <ol className={cn('flex flex-wrap items-center gap-1.5', className)}>
                {steps.map((step, index) => {
                    const state =
                        index < current
                            ? 'done'
                            : index === current
                              ? 'current'
                              : 'pending';

                    return (
                        <li
                            key={step.key}
                            className={cn(
                                'inline-flex h-[30px] items-center gap-1.5 rounded-full px-[10px] text-[12px] font-semibold',
                                state === 'current' &&
                                    'bg-primary-soft text-primary',
                                state === 'done' && 'text-success',
                                state === 'pending' && 'text-muted-foreground',
                            )}
                        >
                            <span
                                className={cn(
                                    'flex size-4 items-center justify-center rounded-full text-[10px] font-bold',
                                    state === 'current' &&
                                        'bg-primary text-white',
                                    state === 'done' &&
                                        'bg-success-solid text-white',
                                    state === 'pending' &&
                                        'bg-border text-muted-foreground',
                                )}
                            >
                                {state === 'done' ? (
                                    <Check className="size-2.5 stroke-[3]" />
                                ) : (
                                    index + 1
                                )}
                            </span>
                            {step.title}
                        </li>
                    );
                })}
            </ol>
        );
    }

    return (
        <ol
            className={cn(
                'flex items-center gap-3 overflow-x-auto rounded-xl border border-border bg-card px-4 py-3 shadow-card',
                className,
            )}
        >
            {steps.map((step, index) => {
                const state =
                    index < current
                        ? 'done'
                        : index === current
                          ? 'current'
                          : 'future';
                const clickable = !!onSelect && !step.disabled && state !== 'future';

                return (
                    <Fragment key={step.key}>
                        <li className="flex shrink-0 items-center">
                            <button
                                type="button"
                                disabled={!clickable}
                                onClick={() => onSelect?.(index)}
                                className={cn(
                                    'flex items-center gap-2.5 rounded-lg px-1 text-left',
                                    clickable
                                        ? 'cursor-pointer'
                                        : 'cursor-default',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-7 items-center justify-center rounded-full border-[1.5px] text-[12.5px] font-bold',
                                        state === 'done' &&
                                            'border-primary bg-primary text-white',
                                        state === 'current' &&
                                            'border-primary bg-white text-primary',
                                        state === 'future' &&
                                            'border-input bg-white text-muted-foreground',
                                    )}
                                >
                                    {state === 'done' ? (
                                        <Check className="size-3.5 stroke-[2.5]" />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <span className="flex flex-col">
                                    <span
                                        className={cn(
                                            'text-[13px] font-semibold whitespace-nowrap',
                                            state === 'future'
                                                ? 'text-muted-foreground'
                                                : 'text-foreground',
                                        )}
                                    >
                                        {step.title}
                                    </span>
                                    {step.subtitle && (
                                        <span className="text-[11.5px] whitespace-nowrap text-muted-foreground">
                                            {step.subtitle}
                                        </span>
                                    )}
                                </span>
                            </button>
                        </li>
                        {index < steps.length - 1 && (
                            <li
                                aria-hidden
                                className={cn(
                                    'h-0.5 min-w-6 flex-1 rounded-full',
                                    index < current ? 'bg-primary' : 'bg-border',
                                )}
                            />
                        )}
                    </Fragment>
                );
            })}
        </ol>
    );
}
