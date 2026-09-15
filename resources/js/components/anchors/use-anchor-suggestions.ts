import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { firstError, getJson, requestJson } from '@/components/anchors/http';
import type {
    AcceptedField,
    AnchorState,
    DetectLiteral,
    FieldSuggestion,
} from '@/components/anchors/types';
import {
    detect as detectRoute,
    index as indexRoute,
} from '@/routes/anchors/envelope';
import {
    accept as acceptRoute,
    accept_all as acceptAllRoute,
    discard as discardRoute,
} from '@/routes/anchors/suggestions';

/** Intervalo da consulta enquanto uma busca (ou o OCR) está em andamento. */
const POLL_MS = 3000;

/**
 * Estado da detecção de campos do envelope aberto no wizard (Fase 3 §3.2).
 *
 * Com a flag `field_anchors` desligada (`enabled = false`) não faz pedido
 * nenhum e os componentes que o usam não desenham nada. As respostas são JSON
 * fora do roteador do Inertia, para não cancelar a gravação automática.
 */
export function useAnchorSuggestions(enabled: boolean) {
    const { envelope } = usePage<{ envelope?: { id?: string } }>().props;
    const envelopeId = envelope?.id ?? null;
    const active = enabled && envelopeId !== null;

    const [state, setState] = useState<AnchorState | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState<string | null>(null);
    const [selectedId, setSelectedId] = useState<string | null>(null);

    const load = useCallback(async (): Promise<void> => {
        if (!active || envelopeId === null) {
            return;
        }

        const response = await getJson<AnchorState>(indexRoute.url(envelopeId));

        if (response.ok && response.body) {
            setState(response.body);
        } else if (response.status !== 404) {
            setError(firstError(response));
        }
    }, [active, envelopeId]);

    useEffect(() => {
        void load();
    }, [load]);

    const busy = state?.busy === true;

    useEffect(() => {
        if (!active || !busy) {
            return;
        }

        const timer = window.setInterval(() => void load(), POLL_MS);

        return () => window.clearInterval(timer);
    }, [active, busy, load]);

    const detect = async (payload: {
        markers: boolean;
        literals: DetectLiteral[];
    }): Promise<boolean> => {
        if (envelopeId === null) {
            return false;
        }

        setPending('detect');
        setError(null);

        const response = await requestJson<AnchorState>(
            'POST',
            detectRoute.url(envelopeId),
            { ...payload },
            { timeoutMs: 120000 },
        );

        setPending(null);

        if (response.ok && response.body) {
            setState(response.body);
            setSelectedId(null);

            return true;
        }

        setError(firstError(response));

        return false;
    };

    const accept = async (
        suggestion: FieldSuggestion,
        recipientId: string | null,
    ): Promise<AcceptedField | null> => {
        if (envelopeId === null) {
            return null;
        }

        setPending(suggestion.id);
        setError(null);

        const response = await requestJson<{
            field: AcceptedField;
            state: AnchorState;
        }>(
            'POST',
            acceptRoute.url([envelopeId, suggestion.id]),
            recipientId ? { recipient_id: recipientId } : {},
        );

        setPending(null);

        if (response.ok && response.body) {
            setState(response.body.state);
            setSelectedId(null);

            return response.body.field;
        }

        setError(firstError(response));
        void load();

        return null;
    };

    const discard = async (suggestion: FieldSuggestion): Promise<void> => {
        if (envelopeId === null) {
            return;
        }

        setPending(suggestion.id);
        setError(null);

        const response = await requestJson<{ state: AnchorState }>(
            'POST',
            discardRoute.url([envelopeId, suggestion.id]),
            {},
        );

        setPending(null);

        if (response.ok && response.body) {
            setState(response.body.state);
            setSelectedId(null);
        } else {
            setError(firstError(response));
            void load();
        }
    };

    const acceptAll = async (): Promise<AcceptedField[]> => {
        if (envelopeId === null) {
            return [];
        }

        setPending('accept_all');
        setError(null);

        const response = await requestJson<{
            fields: AcceptedField[];
            skipped: number;
            state: AnchorState;
        }>('POST', acceptAllRoute.url(envelopeId), {});

        setPending(null);

        if (response.ok && response.body) {
            setState(response.body.state);
            setSelectedId(null);

            return response.body.fields;
        }

        setError(firstError(response));

        return [];
    };

    return {
        enabled: active,
        state,
        error,
        clearError: () => setError(null),
        /** `detect`, `accept_all` ou o id da sugestão em revisão. */
        pending,
        selectedId,
        select: setSelectedId,
        reload: load,
        detect,
        accept,
        discard,
        acceptAll,
    };
}

export type AnchorSuggestions = ReturnType<typeof useAnchorSuggestions>;
