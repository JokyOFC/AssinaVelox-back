import { useId } from 'react';
import { Emphasis, LegalText } from '@/components/sign/legal-text';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

/**
 * Rótulo exato da caixa de aceite (`docs/juridico/declaracao-de-aceite.md` §2),
 * usado quando o servidor não envia `consent_label`. Uma palavra diferente
 * exige nova versão do texto, por isso ele não é remontado em outro lugar.
 */
export function defaultConsentLabel(documentTitle: string): string {
    return `Li o documento **${documentTitle}** e declaro que concordo com seu conteúdo e que os dados aqui registrados — data e hora, endereço IP, navegador, código confirmado por e-mail, a versão exata do documento e os campos que preenchi — constituem evidência do meu aceite eletrônico.`;
}

export interface ConsentBoxProps {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    /** Rótulo ao lado da caixa (§2 do documento de aceite). */
    label: string;
    /** Declaração completa exibida abaixo (§3), já resolvida pelo servidor. */
    statement: string;
    /** Versão do texto exibida ao lado da declaração (ex.: `v1-2026-09-08`). */
    version?: string | null;
    termsUrl: string;
    privacyUrl: string;
    disabled?: boolean;
    className?: string;
}

/**
 * Aceite explícito (arquitetura §2 e §4.5).
 *
 * A caixa **nunca** vem marcada e não é marcada por rolagem: a manifestação
 * tem de ser um ato do signatário. A declaração completa fica visível abaixo
 * (com rolagem própria quando é longa), nunca escondida atrás de um link.
 */
export function ConsentBox({
    checked,
    onCheckedChange,
    label,
    statement,
    version,
    termsUrl,
    privacyUrl,
    disabled = false,
    className,
}: ConsentBoxProps) {
    const id = useId();

    return (
        <div className={cn('flex flex-col gap-2.5', className)}>
            <div className="flex items-start gap-2.5">
                <Checkbox
                    id={id}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={(value) => onCheckedChange(value === true)}
                    className="mt-0.5"
                />
                <label
                    htmlFor={id}
                    className="text-text-secondary cursor-pointer text-[12.5px] leading-[1.5]"
                >
                    <Emphasis text={label} />
                </label>
            </div>

            {statement.trim() !== '' && (
                <div className="border-border bg-sidebar max-h-[220px] overflow-y-auto rounded-[10px] border p-3">
                    <LegalText text={statement} />
                </div>
            )}

            <p className="text-muted-foreground text-[11.5px] leading-[1.5]">
                {version && (
                    <>
                        Versão do texto de aceite:{' '}
                        <span className="tabular">{version}</span> ·{' '}
                    </>
                )}
                <a href={termsUrl} target="_blank" rel="noopener noreferrer">
                    Termos de uso
                </a>{' '}
                ·{' '}
                <a href={privacyUrl} target="_blank" rel="noopener noreferrer">
                    Aviso de privacidade
                </a>
            </p>
        </div>
    );
}
