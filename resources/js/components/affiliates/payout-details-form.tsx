import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PayoutFormData } from './types';

export const EMPTY_PAYOUT: PayoutFormData = {
    pix_key_type: 'cpf',
    pix_key: '',
    holder_name: '',
    holder_tax_id: '',
};

/**
 * Campos dos dados de repasse (chave PIX + titular). Nunca vêm preenchidos: o servidor só
 * devolve a versão mascarada, e atualizar exige informar tudo de novo.
 */
export function PayoutDetailsFields({
    data,
    onChange,
    errors,
    keyTypes,
}: {
    data: PayoutFormData;
    onChange: (key: keyof PayoutFormData, value: string) => void;
    errors: Partial<Record<string, string>>;
    keyTypes: { value: string; label: string }[];
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-1.5">
                <Label htmlFor="pix_key_type">Tipo de chave PIX</Label>
                <Select
                    value={data.pix_key_type}
                    onValueChange={(value) => onChange('pix_key_type', value)}
                >
                    <SelectTrigger id="pix_key_type">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {keyTypes.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.pix_key_type} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="pix_key">Chave PIX</Label>
                <Input
                    id="pix_key"
                    value={data.pix_key}
                    onChange={(e) => onChange('pix_key', e.target.value)}
                    autoComplete="off"
                    maxLength={120}
                    aria-invalid={!!errors.pix_key}
                />
                <InputError message={errors.pix_key} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="holder_name">Nome do titular</Label>
                <Input
                    id="holder_name"
                    value={data.holder_name}
                    onChange={(e) => onChange('holder_name', e.target.value)}
                    autoComplete="off"
                    maxLength={120}
                    aria-invalid={!!errors.holder_name}
                />
                <InputError message={errors.holder_name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="holder_tax_id">CPF ou CNPJ do titular</Label>
                <Input
                    id="holder_tax_id"
                    value={data.holder_tax_id}
                    onChange={(e) => onChange('holder_tax_id', e.target.value)}
                    autoComplete="off"
                    inputMode="numeric"
                    maxLength={20}
                    aria-invalid={!!errors.holder_tax_id}
                />
                <InputError message={errors.holder_tax_id} />
            </div>
        </div>
    );
}
