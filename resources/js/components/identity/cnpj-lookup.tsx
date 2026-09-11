import { Building2, Info, Search } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { postJson } from '@/components/identity/http';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { isValidCnpj, onlyDigits } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CnpjLookupResult } from '@/types/models';

export type CnpjLookupState =
    | { status: 'idle' }
    | { status: 'loading'; cnpj: string }
    | { status: 'done'; cnpj: string; result: CnpjLookupResult }
    | { status: 'failed'; cnpj: string; message: string };

/** O valor digitado é um CNPJ completo e com dígitos válidos (CPF não é consultado). */
export function isLookupableCnpj(value: string): boolean {
    return onlyDigits(value).length === 14 && isValidCnpj(value);
}

/**
 * Autopreenchimento por CNPJ (Fase 2 §2.11, docs/fase-2/identidade.md §3). Nunca bloqueia o
 * formulário: qualquer desfecho (encontrado, não encontrado, indisponível, limite) deixa o
 * preenchimento manual livre. Tempo esgotado e rede viram "indisponível", nunca sucesso.
 */
export function useCnpjLookup(url: string) {
    const [state, setState] = useState<CnpjLookupState>({ status: 'idle' });
    const last = useRef<string | null>(null);

    const lookup = useCallback(
        async (value: string): Promise<CnpjLookupResult | null> => {
            const cnpj = onlyDigits(value);
            last.current = cnpj;
            setState({ status: 'loading', cnpj });

            const response = await postJson<CnpjLookupResult>(
                url,
                { cnpj },
                { timeoutMs: 10000 },
            );

            // Uma resposta atrasada de um CNPJ anterior não sobrescreve a atual.
            if (last.current !== cnpj) {
                return null;
            }

            const body = response.body;

            if (body && typeof body.status === 'string' && body.message) {
                setState({ status: 'done', cnpj, result: body });

                return body;
            }

            setState({
                status: 'failed',
                cnpj,
                message:
                    response.status === 404
                        ? 'A consulta por CNPJ não está disponível agora. Preencha os dados manualmente.'
                        : 'Não foi possível consultar o CNPJ agora. Preencha os dados manualmente.',
            });

            return null;
        },
        [url],
    );

    const reset = useCallback(() => {
        last.current = null;
        setState({ status: 'idle' });
    }, []);

    return { state, lookup, reset };
}

/** Botão "Preencher pelo CNPJ" (discreto, ao lado do campo). */
export function CnpjLookupButton({
    value,
    state,
    onLookup,
    className,
}: {
    value: string;
    state: CnpjLookupState;
    onLookup: () => void;
    className?: string;
}) {
    const loading = state.status === 'loading';

    return (
        <Button
            type="button"
            variant="outline"
            size="xs"
            className={className}
            disabled={loading || !isLookupableCnpj(value)}
            title={
                isLookupableCnpj(value)
                    ? 'Buscar razão social e nome fantasia nos dados abertos do CNPJ'
                    : 'Digite um CNPJ completo para buscar os dados'
            }
            onClick={onLookup}
        >
            {loading ? (
                <Spinner className="size-3" />
            ) : (
                <Search className="size-3" />
            )}
            Preencher pelo CNPJ
        </Button>
    );
}

/**
 * Resultado da consulta: mensagem do servidor, atribuição da fonte, selo "simulado" e a
 * lembrança de que os campos continuam editáveis.
 */
export function CnpjLookupNotice({
    state,
    className,
}: {
    state: CnpjLookupState;
    className?: string;
}) {
    if (state.status === 'idle') {
        return null;
    }

    if (state.status === 'loading') {
        return (
            <p
                aria-live="polite"
                className={cn(
                    'text-muted-foreground flex items-center gap-1.5 text-[12px]',
                    className,
                )}
            >
                <Spinner className="size-3" />
                Consultando o CNPJ… você pode continuar preenchendo.
            </p>
        );
    }

    if (state.status === 'failed') {
        return (
            <p
                aria-live="polite"
                className={cn(
                    'text-muted-foreground flex items-start gap-1.5 text-[12px] leading-[1.5]',
                    className,
                )}
            >
                <Info className="mt-px size-3 shrink-0" />
                {state.message}
            </p>
        );
    }

    const { result } = state;
    const found = result.status === 'found';

    return (
        <div
            aria-live="polite"
            className={cn(
                'flex flex-col gap-1 rounded-[10px] border p-2.5 text-[12px] leading-[1.5]',
                found
                    ? 'border-success-border bg-success-bg'
                    : 'border-border bg-sidebar',
                className,
            )}
        >
            <span
                className={cn(
                    'flex flex-wrap items-center gap-1.5 font-semibold',
                    found ? 'text-success' : 'text-text-secondary',
                )}
            >
                {found ? (
                    <Building2 className="size-3.5 shrink-0" />
                ) : (
                    <Info className="size-3.5 shrink-0" />
                )}
                {result.message}
                {result.source?.simulated && (
                    <Badge
                        variant="warning"
                        className="px-1.5 py-px text-[10.5px]"
                    >
                        simulado
                    </Badge>
                )}
            </span>
            {found && result.data?.registration_status && (
                <span className="text-text-secondary">
                    Situação cadastral: {result.data.registration_status}
                </span>
            )}
            <span className="text-muted-foreground">
                {found
                    ? 'Confira e ajuste os dados antes de salvar: os campos continuam editáveis.'
                    : 'Preencha os dados manualmente; o formulário funciona normalmente.'}
            </span>
            {result.source?.attribution && (
                <span className="text-muted-foreground text-[11.5px]">
                    {result.source.attribution}
                </span>
            )}
        </div>
    );
}
