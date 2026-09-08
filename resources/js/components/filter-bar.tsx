import { Check, ChevronDown, Search, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

export type FilterOption = { value: string; label: string; count?: number };

/**
 * Barra de filtros (DESIGN §4.7 / §4.8): busca com ícone (34px) + chips
 * facetados tracejados que abrem menus, + slot livre à direita.
 */
export function FilterBar({
    children,
    trailing,
    className,
}: {
    children?: ReactNode;
    trailing?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-2 px-4 py-3',
                className,
            )}
        >
            {children}
            {trailing && (
                <div className="ml-auto flex flex-wrap items-center gap-2">
                    {trailing}
                </div>
            )}
        </div>
    );
}

/** Campo de busca com debounce (padrão 300 ms). */
export function SearchInput({
    value,
    onChange,
    placeholder = 'Buscar...',
    debounce = 300,
    className,
    autoFocus,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    debounce?: number;
    className?: string;
    autoFocus?: boolean;
}) {
    const [local, setLocal] = useState(value);

    useEffect(() => {
        setLocal(value);
    }, [value]);

    useEffect(() => {
        if (local === value) {
            return;
        }

        const timer = window.setTimeout(() => onChange(local), debounce);

        return () => window.clearTimeout(timer);
    }, [local, value, debounce, onChange]);

    return (
        <label
            className={cn(
                'border-input text-muted-foreground focus-within:border-primary focus-within:ring-primary/18 flex h-[34px] w-full max-w-[340px] min-w-[180px] flex-1 items-center gap-2 rounded-lg border bg-white px-[10px] focus-within:ring-[3px]',
                className,
            )}
        >
            <Search className="size-[15px] shrink-0" />
            <input
                type="search"
                value={local}
                autoFocus={autoFocus}
                onChange={(e) => setLocal(e.target.value)}
                placeholder={placeholder}
                className="text-foreground placeholder:text-muted-foreground min-w-0 flex-1 border-0 bg-transparent text-[13.5px] outline-none"
            />
            {local && (
                <button
                    type="button"
                    aria-label="Limpar busca"
                    onClick={() => {
                        setLocal('');
                        onChange('');
                    }}
                    className="hover:bg-accent hover:text-foreground rounded p-0.5"
                >
                    <X className="size-3.5" />
                </button>
            )}
        </label>
    );
}

/** Chip facetado de seleção única. */
export function FilterChip({
    label,
    value,
    options,
    onChange,
    icon,
    allLabel = 'Todos',
    className,
}: {
    label: string;
    value: string | null;
    options: FilterOption[];
    onChange: (value: string | null) => void;
    icon?: ReactNode;
    allLabel?: string;
    className?: string;
}) {
    const selected = options.find((o) => o.value === value);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'border-border-dashed text-text-secondary hover:bg-accent-subtle hover:text-foreground inline-flex h-[34px] items-center gap-1.5 rounded-lg border border-dashed bg-white px-[10px] text-[13px] font-medium',
                        selected && 'border-primary text-primary border-solid',
                        className,
                    )}
                >
                    {icon}
                    {label}
                    {selected && (
                        <>
                            <span className="text-border-dashed">|</span>
                            <span className="font-semibold">
                                {selected.label}
                            </span>
                        </>
                    )}
                    <ChevronDown className="text-muted-foreground size-3.5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-48">
                <DropdownMenuLabel className="text-muted-foreground text-[11px] font-bold tracking-[.12em] uppercase">
                    {label}
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={value ?? ''}
                    onValueChange={(next) =>
                        onChange(next === '' ? null : next)
                    }
                >
                    <DropdownMenuRadioItem value="">
                        {allLabel}
                    </DropdownMenuRadioItem>
                    {options.map((option) => (
                        <DropdownMenuRadioItem
                            key={option.value}
                            value={option.value}
                        >
                            <span className="flex-1">{option.label}</span>
                            {option.count !== undefined && (
                                <span className="text-muted-foreground tabular text-[11.5px]">
                                    {option.count}
                                </span>
                            )}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
                {value && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => onChange(null)}>
                            <X className="size-3.5" />
                            Limpar filtro
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Chip facetado de seleção múltipla. */
export function FilterMultiChip({
    label,
    values,
    options,
    onChange,
    icon,
    className,
}: {
    label: string;
    values: string[];
    options: FilterOption[];
    onChange: (values: string[]) => void;
    icon?: ReactNode;
    className?: string;
}) {
    const toggle = (value: string, checked: boolean) => {
        onChange(
            checked
                ? [...values, value]
                : values.filter((current) => current !== value),
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'border-border-dashed text-text-secondary hover:bg-accent-subtle hover:text-foreground inline-flex h-[34px] items-center gap-1.5 rounded-lg border border-dashed bg-white px-[10px] text-[13px] font-medium',
                        values.length > 0 &&
                            'border-primary text-primary border-solid',
                        className,
                    )}
                >
                    {icon}
                    {label}
                    {values.length > 0 && (
                        <span className="bg-primary-soft text-primary rounded-md px-[6px] text-[11.5px] font-semibold">
                            {values.length}
                        </span>
                    )}
                    <ChevronDown className="text-muted-foreground size-3.5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-48">
                {options.map((option) => (
                    <DropdownMenuCheckboxItem
                        key={option.value}
                        checked={values.includes(option.value)}
                        onCheckedChange={(checked) =>
                            toggle(option.value, checked === true)
                        }
                    >
                        {option.label}
                    </DropdownMenuCheckboxItem>
                ))}
                {values.length > 0 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => onChange([])}>
                            <X className="size-3.5" />
                            Limpar filtro
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Chip selecionável (ToggleGroup-like) para listas curtas — DESIGN §4.7. */
export function SelectableChip({
    selected,
    onClick,
    children,
    disabled,
    className,
}: {
    selected: boolean;
    onClick?: () => void;
    children: ReactNode;
    disabled?: boolean;
    className?: string;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            aria-pressed={selected}
            className={cn(
                'inline-flex h-[30px] items-center gap-1 rounded-full border px-[10px] text-[12.5px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                selected
                    ? 'border-primary bg-primary-soft text-primary'
                    : 'border-input text-text-secondary hover:bg-accent-subtle bg-white',
                className,
            )}
        >
            {selected && <Check className="size-3 stroke-[2.5]" />}
            {children}
        </button>
    );
}

/** Botão "Limpar filtros" discreto. */
export function ClearFiltersButton({
    onClick,
    visible,
}: {
    onClick: () => void;
    visible: boolean;
}) {
    if (!visible) {
        return null;
    }

    return (
        <Button type="button" variant="link" size="sm" onClick={onClick}>
            <X className="size-3.5" />
            Limpar filtros
        </Button>
    );
}
