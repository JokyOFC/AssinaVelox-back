import {
    SignatureCapture,
    type SignatureCaptureOptions,
    type SignatureValue,
} from '@/components/signature/signature-capture';
import { initialsOf } from '@/components/signature/signature-image';

export interface InitialsCaptureProps {
    value: SignatureValue | null;
    onChange: (value: SignatureValue | null) => void;
    /** Nome completo do signatário — vira a sugestão de iniciais. */
    name: string;
    options?: SignatureCaptureOptions;
    className?: string;
}

/**
 * Captura da **rubrica** (campo `initials`) — mesmo motor da assinatura, com
 * a moldura menor, o texto sugerido reduzido às iniciais e cópia própria.
 *
 * A rubrica é exigida quando o documento tem campos `initials` (RECONCILIACAO
 * Q10: "rubrica em todas as páginas" gera um campo real por página).
 */
export function InitialsCapture({
    value,
    onChange,
    name,
    options,
    className,
}: InitialsCaptureProps) {
    return (
        <SignatureCapture
            variant="initials"
            value={value}
            onChange={onChange}
            defaultText={initialsOf(name)}
            options={options}
            className={className}
        />
    );
}
