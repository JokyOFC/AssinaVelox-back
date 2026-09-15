import { usePage } from '@inertiajs/react';
import { Video } from 'lucide-react';
import { useEffect, useState } from 'react';
import { getJson } from '@/components/dossier/http';
import { requestJson } from '@/components/identity/http';
import type { IdentityVideoIndex } from '@/components/identity/video-types';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { index as identityVideosIndex } from '@/routes/envelopes/identity_videos';
import { identity_video as identityVideoRoute } from '@/routes/envelopes/recipients';

/** Uma consulta por envelope, compartilhada pelos controles de todos os participantes. */
const indexCache = new Map<string, Promise<IdentityVideoIndex | null>>();

function loadIndex(envelopeId: string): Promise<IdentityVideoIndex | null> {
    let pending = indexCache.get(envelopeId);

    if (!pending) {
        pending = getJson<IdentityVideoIndex>(
            identityVideosIndex(envelopeId).url,
        ).then((response) => (response.ok ? response.body : null));
        indexCache.set(envelopeId, pending);
        // Falha não fica guardada: a próxima montagem tenta de novo.
        void pending.then((body) => {
            if (body === null) {
                indexCache.delete(envelopeId);
            }
        });
    }

    return pending;
}

/**
 * Exigência de vídeo curto por participante (Fase 3 §3.3, flag `identity_video`) no passo de
 * participantes do wizard. `PUT envelopes.recipients.identity_video`, por `fetch` (JSON): uma
 * visita do Inertia cancelaria a gravação automática dos participantes.
 *
 * Com a flag desligada não renderiza nada. O texto diz o que o vídeo é: um registro anexado ao
 * aceite, sem som, que não verifica identidade.
 */
export function VideoRequirementControl({
    envelopeId,
    recipientId,
    recipientName,
    disabled,
}: {
    envelopeId: string;
    /** ULID; `null` enquanto o participante ainda não foi salvo. */
    recipientId: string | null;
    recipientName: string;
    disabled?: boolean;
}) {
    const enabled = Boolean(usePage().props.features?.identity_video);
    const [index, setIndex] = useState<IdentityVideoIndex | null>(null);
    const [required, setRequired] = useState(false);
    const [seconds, setSeconds] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let alive = true;

        void loadIndex(envelopeId).then((body) => {
            if (!alive || body === null) {
                return;
            }

            setIndex(body);

            const current = recipientId
                ? body.requirements[recipientId]
                : undefined;
            setRequired(current !== undefined);
            setSeconds(current?.max_seconds ?? null);
        });

        return () => {
            alive = false;
        };
    }, [enabled, envelopeId, recipientId]);

    if (!enabled) {
        return null;
    }

    const limits = index?.limits;
    const effective = seconds ?? limits?.default_seconds ?? 10;
    const options = Array.from(
        new Set([5, 10, 15, 20, 30, limits?.default_seconds ?? 10, effective]),
    )
        .filter(
            (value) => value >= 3 && value <= (limits?.ceiling_seconds ?? 30),
        )
        .sort((a, b) => a - b);

    const save = async (nextRequired: boolean, nextSeconds: number | null) => {
        if (recipientId === null) {
            return;
        }

        const before = { required, seconds };
        setRequired(nextRequired);
        setSeconds(nextSeconds);
        setSaving(true);
        setError(null);

        const response = await requestJson<{
            required?: boolean;
            max_seconds?: number | null;
            message?: string;
            errors?: Record<string, string[]>;
        }>(
            'PUT',
            identityVideoRoute({ envelope: envelopeId, recipient: recipientId })
                .url,
            {
                required: nextRequired,
                max_seconds: nextRequired ? nextSeconds : null,
            },
        );

        setSaving(false);

        if (response.ok && response.body) {
            setRequired(Boolean(response.body.required));
            setSeconds(response.body.max_seconds ?? null);
            indexCache.delete(envelopeId);

            return;
        }

        setRequired(before.required);
        setSeconds(before.seconds);
        setError(
            response.network
                ? 'Não foi possível salvar agora. Verifique a conexão e tente de novo.'
                : (Object.values(response.body?.errors ?? {})[0]?.[0] ??
                      response.body?.message ??
                      'Não foi possível salvar a exigência de vídeo.'),
        );
    };

    const unsaved = recipientId === null;

    return (
        <div className="border-muted flex flex-col gap-2 border-t pt-3">
            <label className="flex items-start gap-2.5 text-[13px]">
                <Checkbox
                    checked={required}
                    disabled={disabled || unsaved || saving || index === null}
                    onCheckedChange={(value) =>
                        void save(value === true, seconds)
                    }
                    className="mt-0.5"
                    aria-label={`Exigir vídeo curto de ${recipientName || 'este participante'}`}
                />
                <span className="min-w-0">
                    <span className="flex items-center gap-1.5 font-semibold">
                        <Video className="text-primary size-3.5" />
                        Exigir vídeo curto antes do aceite
                        {saving && <Spinner className="size-3" />}
                    </span>
                    <span className="text-muted-foreground block text-[12px] leading-[1.45]">
                        {unsaved
                            ? 'Salve o participante para escolher.'
                            : `${recipientName || 'O participante'} grava um vídeo curto do rosto, sem som, antes de concluir. É só um registro anexado ao aceite: não verifica identidade.`}
                    </span>
                </span>
            </label>

            {required && !unsaved && (
                <label className="text-text-secondary flex items-center gap-2 pl-7 text-[12.5px]">
                    Duração máxima
                    <select
                        className="border-input bg-background h-8 rounded-md border px-2 text-[12.5px]"
                        value={effective}
                        disabled={disabled || saving}
                        onChange={(event) =>
                            void save(true, Number(event.target.value))
                        }
                    >
                        {options.map((value) => (
                            <option key={value} value={value}>
                                {value} segundos
                            </option>
                        ))}
                    </select>
                </label>
            )}

            <InputError message={error ?? undefined} />
        </div>
    );
}
