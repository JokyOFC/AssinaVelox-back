import { usePage } from '@inertiajs/react';
import { Download, Info, Play, Video } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { getJson } from '@/components/dossier/http';
import { formatSeconds } from '@/components/identity/video-capture-step';
import type {
    IdentityVideoIndex,
    IdentityVideoItem,
} from '@/components/identity/video-types';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime } from '@/lib/format';
import { index as identityVideosIndex } from '@/routes/envelopes/identity_videos';

/**
 * Vídeos curtos enviados pelos participantes, no detalhe do envelope (Fase 3 §3.3, flag
 * `identity_video`). Só para quem vê o envelope; nunca na verificação pública.
 *
 * As URLs de reprodução e download são assinadas e valem poucos minutos: cada clique pede uma
 * lista nova antes de tocar ou baixar. Cada URL servida fica na trilha do envelope.
 */
export function IdentityVideoPanel({ envelopeId }: { envelopeId: string }) {
    const enabled = Boolean(usePage().props.features?.identity_video);
    const [data, setData] = useState<IdentityVideoIndex | null>(null);
    const [playing, setPlaying] = useState<{ id: string; url: string } | null>(
        null,
    );
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async (): Promise<IdentityVideoIndex | null> => {
        const response = await getJson<IdentityVideoIndex>(
            identityVideosIndex(envelopeId).url,
        );

        if (response.ok && response.body) {
            setData(response.body);

            return response.body;
        }

        return null;
    }, [envelopeId]);

    useEffect(() => {
        if (enabled) {
            void load();
        }
    }, [enabled, load]);

    if (!enabled || !data || data.items.length === 0) {
        return null;
    }

    const fresh = async (id: string): Promise<IdentityVideoItem | null> => {
        setBusy(id);
        setError(null);
        const body = await load();
        setBusy(null);

        const item =
            body?.items.find((candidate) => candidate.id === id) ?? null;

        if (!item?.available) {
            setError('O vídeo não está mais disponível.');

            return null;
        }

        return item;
    };

    const play = async (id: string) => {
        const item = await fresh(id);

        if (item?.play_url) {
            setPlaying({ id, url: item.play_url });
        }
    };

    const download = async (id: string) => {
        const item = await fresh(id);

        if (item?.download_url) {
            window.location.assign(item.download_url);
        }
    };

    return (
        <section
            className="border-border bg-card flex flex-col gap-3 rounded-[12px] border p-4"
            aria-labelledby="identity-videos-title"
        >
            <h2
                id="identity-videos-title"
                className="flex items-center gap-1.5 text-[14px] font-semibold"
            >
                <Video className="text-primary size-4" />
                Vídeos curtos enviados pelos participantes
            </h2>

            <p className="border-border bg-sidebar text-text-secondary flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                <Info className="text-primary mt-0.5 size-3.5 shrink-0" />
                <span>{data.notice}</span>
            </p>

            <ul className="flex flex-col gap-3">
                {data.items.map((item) => (
                    <li
                        key={item.id}
                        className="border-border flex flex-col gap-2 rounded-[10px] border p-3"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0 text-[12.5px] leading-[1.5]">
                                <span className="block text-[13px] font-semibold">
                                    {item.recipient_name ?? 'Participante'}
                                </span>
                                <span className="text-muted-foreground block">
                                    {item.source_label}
                                </span>
                                <span className="text-muted-foreground block">
                                    {[
                                        formatDateTime(item.captured_at),
                                        item.container_label,
                                        formatSeconds(
                                            item.duration_ms ??
                                                item.declared_duration_ms,
                                        ),
                                        formatBytes(item.size_bytes),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                                <span
                                    className="text-muted-foreground block truncate font-mono text-[10.5px]"
                                    title={item.sha256}
                                >
                                    SHA-256 {item.sha256}
                                </span>
                            </div>

                            {item.available ? (
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={busy !== null}
                                        onClick={() => void play(item.id)}
                                    >
                                        {busy === item.id ? (
                                            <Spinner />
                                        ) : (
                                            <Play className="size-3.5" />
                                        )}
                                        Reproduzir
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        disabled={busy !== null}
                                        onClick={() => void download(item.id)}
                                    >
                                        <Download className="size-3.5" />
                                        Baixar
                                    </Button>
                                </div>
                            ) : (
                                <span className="text-muted-foreground text-[12px]">
                                    {item.purged_at
                                        ? `Arquivo apagado pela retenção em ${formatDateTime(item.purged_at)}`
                                        : 'Arquivo indisponível'}
                                </span>
                            )}
                        </div>

                        {playing?.id === item.id && (
                            <video
                                key={playing.url}
                                src={playing.url}
                                controls
                                autoPlay
                                playsInline
                                preload="auto"
                                className="bg-muted max-h-[360px] w-full rounded-[8px]"
                                aria-label={`${item.kind_label} de ${item.recipient_name ?? 'participante'}`}
                            />
                        )}
                    </li>
                ))}
            </ul>

            {error && (
                <p role="alert" className="text-danger text-[12.5px]">
                    {error}
                </p>
            )}
        </section>
    );
}
