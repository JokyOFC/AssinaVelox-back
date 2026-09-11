import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatCnpj, formatCpf } from '@/lib/format';
import type { VariableOptions, VariableType } from './types';

/** "dd/mm/aaaa" → "aaaa-mm-dd" (o input de data trabalha em ISO). */
export function toIsoDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value.trim());

    return match ? `${match[3]}-${match[2]}-${match[1]}` : value;
}

function formatPhone(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 11);

    if (digits.length <= 2) {
        return digits;
    }

    if (digits.length <= 6) {
        return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
    }

    return digits.length === 11
        ? `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`
        : `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
}

/**
 * Controle de preenchimento por tipo de variável. Só ajuda na digitação: a
 * validação que vale é a do servidor (VariableValues).
 */
export function VariableInput({
    id,
    type,
    options,
    value,
    onChange,
    invalid,
    placeholder,
}: {
    id: string;
    type: VariableType;
    options: VariableOptions;
    value: string;
    onChange: (value: string) => void;
    invalid?: boolean;
    placeholder?: string;
}) {
    const common = {
        id,
        'aria-invalid': invalid || undefined,
    };

    switch (type) {
        case 'long_text':
            return (
                <Textarea
                    {...common}
                    rows={4}
                    value={value}
                    maxLength={Number(options.max_length) || 5000}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder}
                />
            );
        case 'number':
            return (
                <Input
                    {...common}
                    inputMode="decimal"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder ?? 'Ex.: 12'}
                />
            );
        case 'currency':
            return (
                <div className="relative">
                    <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-[13.5px]">
                        R$
                    </span>
                    <Input
                        {...common}
                        inputMode="decimal"
                        className="pl-9"
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                        placeholder="0,00"
                    />
                </div>
            );
        case 'date':
            return (
                <Input
                    {...common}
                    type="date"
                    value={toIsoDate(value)}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
        case 'cpf':
            return (
                <Input
                    {...common}
                    inputMode="numeric"
                    value={value}
                    onChange={(e) => onChange(formatCpf(e.target.value))}
                    placeholder="000.000.000-00"
                />
            );
        case 'cnpj':
            return (
                <Input
                    {...common}
                    inputMode="numeric"
                    value={value}
                    onChange={(e) => onChange(formatCnpj(e.target.value))}
                    placeholder="00.000.000/0000-00"
                />
            );
        case 'email':
            return (
                <Input
                    {...common}
                    type="email"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder ?? 'nome@empresa.com.br'}
                />
            );
        case 'phone':
            return (
                <Input
                    {...common}
                    type="tel"
                    value={value}
                    onChange={(e) => onChange(formatPhone(e.target.value))}
                    placeholder="(11) 98765-4321"
                />
            );
        case 'select':
            return (
                <Select value={value || undefined} onValueChange={onChange}>
                    <SelectTrigger {...common} className="w-full">
                        <SelectValue placeholder="Escolha uma opção" />
                    </SelectTrigger>
                    <SelectContent>
                        {(options.choices ?? []).map((choice) => (
                            <SelectItem key={choice} value={choice}>
                                {choice}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            );
        case 'boolean':
            return (
                <label className="flex items-center gap-2 text-[13.5px]">
                    <Checkbox
                        id={id}
                        checked={value === '1' || value === 'true'}
                        onCheckedChange={(checked) =>
                            onChange(checked === true ? '1' : '0')
                        }
                    />
                    Sim
                </label>
            );
        default:
            return (
                <Input
                    {...common}
                    value={value}
                    maxLength={Number(options.max_length) || 500}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder}
                />
            );
    }
}
