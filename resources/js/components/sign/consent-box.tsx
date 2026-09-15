import { useId, useState } from 'react';
import { Emphasis, LegalText } from '@/components/sign/legal-text';
import { Checkbox } from '@/components/ui/checkbox';
import { useI18n } from '@/i18n';
import { cn } from '@/lib/utils';

/**
 * Rótulo exato da caixa de aceite (`docs/juridico/declaracao-de-aceite.md` §2),
 * usado quando o servidor não envia `consent_label`. Uma palavra diferente
 * exige nova versão do texto, por isso ele não é remontado em outro lugar.
 */
export function defaultConsentLabel(documentTitle: string): string {
    return `Li o documento **${documentTitle}** e declaro que concordo com seu conteúdo e que os dados aqui registrados — data e hora, endereço IP, navegador, código confirmado por e-mail, a versão exata do documento e os campos que preenchi — constituem evidência do meu aceite eletrônico.`;
}

/**
 * Fase 3 §3.3 (F-I18N): tradução de CORTESIA do rótulo e da declaração
 * (`consent.translation`, só em `en`/`es`). O texto de referência — o que o
 * servidor confere e grava — continua em `label`/`statement`.
 */
export interface ConsentTranslation {
    locale: string;
    checkbox_label: string;
    statement: string;
    /** A tradução jurídica deste idioma já foi revisada por profissional? */
    reviewed: boolean;
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
    /** Tradução de cortesia (F-I18N); ausente = a tela de sempre. */
    translation?: ConsentTranslation | null;
}

/**
 * Aceite explícito (arquitetura §2 e §4.5).
 *
 * A caixa **nunca** vem marcada e não é marcada por rolagem: a manifestação
 * tem de ser um ato do signatário. A declaração completa fica visível abaixo
 * (com rolagem própria quando é longa), nunca escondida atrás de um link.
 *
 * Com tradução de cortesia, a tela diz isso antes da declaração e oferece o
 * texto de referência (PT-BR) a um clique — é ele que fica registrado.
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
    translation = null,
}: ConsentBoxProps) {
    const id = useId();
    const { t, isReference } = useI18n();
    const [showReference, setShowReference] = useState(false);

    const shownLabel = translation?.checkbox_label ?? label;
    const shownStatement = translation?.statement ?? statement;
    const onlyPortuguese = isReference ? '' : ` ${t('common.portuguese_only')}`;

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
                    <Emphasis text={shownLabel} />
                </label>
            </div>

            {translation && (
                <div
                    role="note"
                    className="border-warning-border bg-warning-bg text-text-secondary rounded-[10px] border p-2.5 text-[12px] leading-[1.5]"
                >
                    <b className="text-foreground">
                        {translation.reviewed
                            ? t('consent.reviewed_title')
                            : t('consent.courtesy_title')}
                    </b>
                    {' — '}
                    {t('consent.courtesy_body')}{' '}
                    <button
                        type="button"
                        onClick={() => setShowReference((value) => !value)}
                        aria-expanded={showReference}
                        className="text-primary font-semibold hover:underline"
                    >
                        {showReference
                            ? t('consent.hide_reference')
                            : t('consent.show_reference')}
                    </button>
                </div>
            )}

            {shownStatement.trim() !== '' && (
                <div className="border-border bg-sidebar max-h-[220px] overflow-y-auto rounded-[10px] border p-3">
                    <LegalText text={shownStatement} />
                </div>
            )}

            {translation && showReference && statement.trim() !== '' && (
                <div
                    lang="pt-BR"
                    className="border-border max-h-[220px] overflow-y-auto rounded-[10px] border border-dashed p-3"
                >
                    <LegalText text={statement} />
                </div>
            )}

            <p className="text-muted-foreground text-[11.5px] leading-[1.5]">
                {version && (
                    <>
                        {t('consent.version')}{' '}
                        <span className="tabular">{version}</span> ·{' '}
                    </>
                )}
                <a href={termsUrl} target="_blank" rel="noopener noreferrer">
                    {t('common.terms')}
                    {onlyPortuguese}
                </a>{' '}
                ·{' '}
                <a href={privacyUrl} target="_blank" rel="noopener noreferrer">
                    {t('common.privacy')}
                    {onlyPortuguese}
                </a>
            </p>
        </div>
    );
}
