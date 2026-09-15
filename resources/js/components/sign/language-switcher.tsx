import { router } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { useState } from 'react';
import { BCP47 } from '@/i18n/locales';
import { useI18n, useSignerI18nProps } from '@/i18n';
import { cn } from '@/lib/utils';

/**
 * Seletor de idioma da página pública (Fase 3 §3.3 — F-I18N,
 * docs/fase-3/multilingue.md §4). Só existe quando o servidor enviou a prop
 * `i18n` com a URL de troca — flag `multilingual` ligada; sem ela não renderiza
 * nada e o cabeçalho é o de sempre.
 *
 * A troca vale para esta sessão do navegador (`POST sign.locale.update`), vai
 * para a trilha e não muda o idioma que quem enviou registrou para os e-mails.
 */
export function LanguageSwitcher({ className }: { className?: string }) {
    const shared = useSignerI18nProps();
    const { t } = useI18n();
    const [pending, setPending] = useState(false);

    if (!shared?.switch_url) {
        return null;
    }

    const switchUrl = shared.switch_url;

    return (
        <label
            className={cn(
                'text-muted-foreground flex items-center gap-1.5 text-[12px]',
                className,
            )}
            title={t('layout.language_hint')}
        >
            <Languages className="size-3.5 shrink-0" aria-hidden />
            <span className="sr-only">{t('layout.language')}</span>
            <select
                value={shared.locale}
                disabled={pending}
                aria-label={t('layout.language')}
                data-testid="signer-language"
                onChange={(event) => {
                    setPending(true);
                    router.post(
                        switchUrl,
                        { locale: event.target.value },
                        {
                            preserveScroll: true,
                            onFinish: () => setPending(false),
                        },
                    );
                }}
                className="border-input text-foreground h-7 max-w-[150px] rounded-md border bg-white px-1.5 text-[12px] disabled:opacity-60"
            >
                {shared.locales.map((option) => (
                    <option
                        key={option.code}
                        value={option.code}
                        lang={BCP47[option.code]}
                    >
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
