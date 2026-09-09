import { Fragment, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Renderiza os textos jurídicos que o servidor envia já resolvidos
 * (`docs/juridico/*`): parágrafos separados por linha em branco, itens
 * numerados "1." e ênfase `**negrito**`.
 *
 * Deliberadamente **não** interpreta HTML: o texto é dado, não marcação, e
 * nunca é injetado com `dangerouslySetInnerHTML`. O que não for reconhecido
 * aparece literalmente, o que é o comportamento seguro para um texto que será
 * gravado como evidência exatamente como foi exibido.
 */
export function LegalText({
    text,
    className,
}: {
    text: string;
    className?: string;
}) {
    const blocks = text
        .replace(/\r\n/g, '\n')
        .split(/\n{2,}/)
        .map((block) => block.trim())
        .filter((block) => block !== '');

    return (
        <div
            className={cn(
                'text-text-secondary flex flex-col gap-2 text-[12.5px] leading-[1.55]',
                className,
            )}
        >
            {blocks.map((block, index) => {
                const numbered = /^\d+\.\s/.test(block);

                return (
                    <p key={index} className={cn(numbered && 'pl-4 -indent-4')}>
                        <Emphasis text={block} />
                    </p>
                );
            })}
        </div>
    );
}

/**
 * Converte `**trecho**` em `<strong>` sem passar por HTML.
 *
 * Só um par BALANCEADO e NÃO VAZIO conta como marcação: `**` precisa abrir colado a um
 * caractere visível e fechar colado a outro. Qualquer outra sequência de asteriscos —
 * a máscara de um e-mail, um asterisco solto no texto jurídico — aparece literalmente,
 * como o cabeçalho deste arquivo promete. A versão anterior fazia `split('**')` e
 * engolia os asteriscos de `m**********@exemplo.test`, exibindo `m@exemplo.test`: um
 * endereço que não é o do signatário e não é o que fica gravado como evidência.
 */
export function Emphasis({ text }: { text: string }) {
    const pattern = /\*\*(?=\S)([\s\S]*?\S)\*\*/g;
    const nodes: ReactNode[] = [];
    let cursor = 0;
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > cursor) {
            nodes.push(
                <Fragment key={cursor}>
                    {text.slice(cursor, match.index)}
                </Fragment>,
            );
        }

        nodes.push(
            <strong
                key={`b${match.index}`}
                className="text-foreground font-semibold"
            >
                {match[1]}
            </strong>,
        );

        cursor = match.index + match[0].length;
    }

    if (cursor < text.length) {
        nodes.push(<Fragment key={cursor}>{text.slice(cursor)}</Fragment>);
    }

    return <>{nodes}</>;
}
