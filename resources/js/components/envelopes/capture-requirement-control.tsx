import { Camera } from 'lucide-react';
import { useState } from 'react';
import { requestJson } from '@/components/identity/http';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { captureKindShortLabels } from '@/lib/labels';
import { identity_capture as identityCaptureRoute } from '@/routes/envelopes/recipients';
import type { CaptureKind } from '@/types/enums';

const KINDS: CaptureKind[] = ['selfie', 'document_front', 'document_back'];

/**
 * Exigência de captura simples por participante (Fase 2 §2.10, flag `identity_capture`):
 * foto do rosto e/ou do documento antes do aceite. `PUT envelopes.recipients.identity_capture`.
 *
 * A captura é só uma imagem enviada pelo participante e anexada ao registro do aceite:
 * não compara rostos, não analisa a imagem e não lê o documento. O texto diz isso.
 *
 * Grava por `fetch` (JSON), não pelo roteador do Inertia: uma visita do Inertia cancelaria
 * a gravação automática dos participantes que estivesse no ar.
 */
export function CaptureRequirementControl({
    envelopeId,
    recipientId,
    recipientName,
    kinds,
    onSaved,
    disabled,
}: {
    envelopeId: string;
    /** ULID; `null` enquanto o participante ainda não foi salvo. */
    recipientId: string | null;
    recipientName: string;
    kinds: CaptureKind[];
    onSaved: (kinds: CaptureKind[]) => void;
    disabled?: boolean;
}) {
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [optimistic, setOptimistic] = useState<CaptureKind[] | null>(null);
    const current = optimistic ?? kinds;
    const unsaved = recipientId === null;

    const toggle = async (kind: CaptureKind, checked: boolean) => {
        if (recipientId === null) {
            return;
        }

        let next = checked
            ? [...new Set([...current, kind])]
            : current.filter((item) => item !== kind);

        // O verso só existe junto com a frente (mesma regra do servidor).
        if (!next.includes('document_front')) {
            next = next.filter((item) => item !== 'document_back');
        }

        next = KINDS.filter((item) => next.includes(item));

        setOptimistic(next);
        setSaving(true);
        setError(null);

        const response = await requestJson<{
            kinds?: CaptureKind[];
            message?: string;
            errors?: { kinds?: string[] };
        }>(
            'PUT',
            identityCaptureRoute({
                envelope: envelopeId,
                recipient: recipientId,
            }).url,
            { kinds: next },
        );

        setSaving(false);
        setOptimistic(null);

        if (response.ok) {
            onSaved(response.body?.kinds ?? next);

            return;
        }

        setError(
            response.body?.errors?.kinds?.[0] ??
                response.body?.message ??
                (response.network
                    ? 'Não foi possível salvar a exigência de fotos. Verifique a conexão e tente de novo.'
                    : 'Não foi possível salvar a exigência de fotos.'),
        );
    };

    return (
        <div className="flex flex-col gap-1.5">
            <span className="flex items-center gap-1.5 text-[12.5px] font-semibold">
                <Camera className="size-3.5" />
                Fotos antes do aceite{' '}
                <span className="text-muted-foreground font-normal">
                    (opcional)
                </span>
                {saving && <Spinner className="text-muted-foreground size-3" />}
            </span>
            <div
                className="flex flex-wrap gap-x-4 gap-y-1.5"
                role="group"
                aria-label={`Fotos exigidas de ${recipientName || 'participante'}`}
            >
                {KINDS.map((kind) => {
                    const checked = current.includes(kind);
                    const blocked =
                        kind === 'document_back' &&
                        !current.includes('document_front');

                    return (
                        <label
                            key={kind}
                            className="text-text-secondary flex cursor-pointer items-center gap-2 text-[12.5px] has-disabled:cursor-not-allowed has-disabled:opacity-60"
                        >
                            <Checkbox
                                checked={checked}
                                disabled={
                                    disabled || unsaved || saving || blocked
                                }
                                onCheckedChange={(value) =>
                                    void toggle(kind, value === true)
                                }
                            />
                            {captureKindShortLabels[kind]}
                        </label>
                    );
                })}
            </div>
            <span className="text-muted-foreground text-[11.5px] leading-[1.5]">
                {unsaved
                    ? 'Disponível depois que nome e e-mail forem salvos.'
                    : 'Captura simples: a imagem fica anexada ao registro do aceite. Não há comparação de rostos nem leitura do documento, e isso não confirma a identidade de ninguém.'}
            </span>
            <InputError message={error ?? undefined} />
        </div>
    );
}
