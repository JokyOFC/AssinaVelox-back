import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CloudDownload,
    Loader2,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { xsrfToken } from '@/components/identity/http';
import InputError from '@/components/input-error';
import {
    openDropboxChooser,
    openGooglePicker,
} from '@/components/integrations/cloud/pickers';
import type { CloudImportPageProps } from '@/components/integrations/cloud/types';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatBytes, formatDateMedium, plural } from '@/lib/format';
import { store as dropboxStore } from '@/routes/cloud_import/dropbox';
import {
    start as googleStart,
    store as googleStore,
    token as googleToken,
} from '@/routes/cloud_import/google';
import { index as envelopesIndex } from '@/routes/envelopes';

type Busy = null | 'google_drive' | 'dropbox';

interface GoogleTokenResponse {
    access_token: string;
    api_key: string;
    app_id: string;
}

/**
 * Token do Picker: POST sem cache, só na hora de abrir o seletor. Nunca vai para prop,
 * estado persistente ou armazenamento do navegador.
 */
async function fetchGoogleToken(
    url: string,
): Promise<GoogleTokenResponse | string> {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const body = (await response.json().catch(() => null)) as
            | (Partial<GoogleTokenResponse> & { message?: string })
            | null;

        if (!response.ok || !body?.access_token) {
            return (
                body?.message ??
                'Autorize o Google Drive de novo para escolher os arquivos.'
            );
        }

        return {
            access_token: body.access_token,
            api_key: body.api_key ?? '',
            app_id: body.app_id ?? '',
        };
    } catch {
        return 'Sem conexão com o servidor. Tente de novo.';
    }
}

function ProviderCard({
    title,
    description,
    available,
    simulated,
    unavailableText,
    children,
}: {
    title: string;
    description: string;
    available: boolean;
    simulated: boolean;
    unavailableText: string;
    children: ReactNode;
}) {
    return (
        <section className="border-border bg-card shadow-card flex min-w-0 flex-[1_1_300px] flex-col gap-3 rounded-xl border p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-[15px] font-semibold">{title}</h2>
                {available ? (
                    <Badge variant={simulated ? 'warning' : 'success'}>
                        {simulated ? 'Simulador de teste' : 'Disponível'}
                    </Badge>
                ) : (
                    <Badge variant="neutral">
                        Aguardando app registrado pelo proprietário
                    </Badge>
                )}
            </div>
            <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                {available ? description : unavailableText}
            </p>
            <div className="mt-auto flex flex-wrap gap-2">{children}</div>
        </section>
    );
}

/**
 * Importar da nuvem (Fase 3 §3.9, G-CONN — docs/fase-3/conectores.md §4). Estado honesto de
 * cada provedor: sem o app registrado pelo proprietário, o botão fica desabilitado e a tela
 * diz isso. O arquivo escolhido entra pelo mesmo caminho de um envio do computador.
 */
export default function CloudImportPage({
    envelope,
    editable,
    capacity,
    providers,
    recent,
}: CloudImportPageProps) {
    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };
    const [busy, setBusy] = useState<Busy>(null);
    const [localError, setLocalError] = useState<string | null>(null);
    const google = providers.google_drive;
    const dropbox = providers.dropbox;
    const multiselect = capacity.remaining > 1;

    const pickFromGoogle = async (): Promise<void> => {
        setLocalError(null);
        setBusy('google_drive');

        const token = await fetchGoogleToken(googleToken.url(envelope.id));

        if (typeof token === 'string') {
            setLocalError(token);
            setBusy(null);

            return;
        }

        try {
            await openGooglePicker({
                token: token.access_token,
                apiKey: token.api_key,
                appId: token.app_id,
                maxItems: capacity.remaining,
                onPicked: (fileIds) =>
                    router.post(
                        googleStore.url(envelope.id),
                        { file_ids: fileIds },
                        { onFinish: () => setBusy(null) },
                    ),
                onCancel: () => setBusy(null),
            });
        } catch {
            setLocalError(
                'Não foi possível abrir o seletor do Google Drive neste navegador.',
            );
            setBusy(null);
        }
    };

    const pickFromDropbox = async (): Promise<void> => {
        if (!dropbox.app_key) {
            return;
        }

        setLocalError(null);
        setBusy('dropbox');

        try {
            await openDropboxChooser({
                appKey: dropbox.app_key,
                multiselect,
                extensions: capacity.extensions,
                sizeLimit: capacity.max_bytes,
                onPicked: (files) =>
                    router.post(
                        dropboxStore.url(envelope.id),
                        {
                            files: files.map((file) => ({
                                link: file.link,
                                name: file.name,
                                id: file.id,
                                bytes: file.bytes,
                            })),
                        },
                        { onFinish: () => setBusy(null) },
                    ),
                onCancel: () => setBusy(null),
            });
        } catch {
            setLocalError(
                'Não foi possível abrir o seletor do Dropbox neste navegador.',
            );
            setBusy(null);
        }
    };

    return (
        <>
            <Head title="Importar da nuvem" />
            <PageHeader
                title="Importar da nuvem"
                subtitle={`Documento: ${envelope.title}`}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <a href={envelope.edit_url}>
                            <ArrowLeft className="size-[15px]" />
                            Voltar ao documento
                        </a>
                    </Button>
                }
            />

            {!editable ? (
                <div className="border-border bg-card shadow-card rounded-xl border p-5 text-[13.5px]">
                    Este documento já saiu da preparação e não aceita novos
                    arquivos.
                </div>
            ) : (
                <div className="flex flex-col gap-5">
                    <p className="text-text-secondary flex items-start gap-2 text-[12.5px] leading-[1.5]">
                        <CloudDownload className="mt-0.5 size-3.5 shrink-0" />
                        <span>
                            {capacity.replaces
                                ? 'Este documento aceita um arquivo: importar substitui o arquivo atual.'
                                : `Até ${plural(capacity.remaining, 'arquivo')} nesta importação.`}{' '}
                            Até {formatBytes(capacity.max_bytes)} cada, nos
                            formatos{' '}
                            {capacity.extensions
                                .map((extension) => extension.toUpperCase())
                                .join(', ')}
                            . O arquivo passa pelas mesmas verificações de um
                            envio do computador: tipo real pelo conteúdo,
                            tamanho e proteção por senha.
                        </span>
                    </p>

                    <InputError message={localError ?? errors?.file} />

                    <div className="flex flex-wrap gap-5">
                        <ProviderCard
                            title="Google Drive"
                            available={google.available}
                            simulated={google.simulated}
                            description="O AssinaVelox só acessa os arquivos que você escolher e não guarda o acesso depois da importação."
                            unavailableText="Ainda não disponível: a importação depende de um app do Google registrado pelo proprietário da plataforma."
                        >
                            {!google.available ? (
                                <Button size="sm" disabled>
                                    Conectar ao Google Drive
                                </Button>
                            ) : google.authorized ? (
                                <Button
                                    size="sm"
                                    disabled={busy !== null}
                                    onClick={() => void pickFromGoogle()}
                                >
                                    {busy === 'google_drive' && (
                                        <Loader2 className="size-[15px] animate-spin" />
                                    )}
                                    Escolher arquivos no Google Drive
                                </Button>
                            ) : (
                                <Button size="sm" asChild>
                                    <a href={googleStart.url(envelope.id)}>
                                        Conectar ao Google Drive
                                    </a>
                                </Button>
                            )}
                        </ProviderCard>

                        <ProviderCard
                            title="Dropbox"
                            available={dropbox.available}
                            simulated={dropbox.simulated}
                            description="O Dropbox gera um link temporário do arquivo escolhido; o AssinaVelox baixa na hora e não se conecta à sua conta."
                            unavailableText="Ainda não disponível: a importação depende de um app do Dropbox registrado pelo proprietário da plataforma."
                        >
                            <Button
                                size="sm"
                                disabled={
                                    !dropbox.available ||
                                    !dropbox.app_key ||
                                    busy !== null
                                }
                                onClick={() => void pickFromDropbox()}
                            >
                                {busy === 'dropbox' && (
                                    <Loader2 className="size-[15px] animate-spin" />
                                )}
                                Escolher no Dropbox
                            </Button>
                        </ProviderCard>
                    </div>

                    {recent.length > 0 && (
                        <section className="border-border bg-card shadow-card rounded-xl border p-5">
                            <h2 className="mb-3 text-[15px] font-semibold">
                                Importações deste documento
                            </h2>
                            <ul className="divide-border divide-y">
                                {recent.map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13px]"
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            {row.status === 'completed' ? (
                                                <Check className="text-success size-3.5 shrink-0" />
                                            ) : (
                                                <TriangleAlert className="text-danger size-3.5 shrink-0" />
                                            )}
                                            <span className="truncate">
                                                {row.name ??
                                                    'Arquivo não importado'}
                                            </span>
                                            <span className="text-muted-foreground shrink-0">
                                                · {row.provider_label}
                                            </span>
                                            {row.simulated && (
                                                <Badge variant="warning">
                                                    Simulado
                                                </Badge>
                                            )}
                                        </span>
                                        <span className="text-muted-foreground tabular text-[12px]">
                                            {row.status === 'completed'
                                                ? 'Importado'
                                                : `Recusado: ${row.reason ?? 'motivo não informado'}`}
                                            {row.created_at &&
                                                ` · ${formatDateMedium(row.created_at)}`}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            )}
        </>
    );
}

CloudImportPage.layout = {
    breadcrumbs: [{ title: 'Documentos', href: envelopesIndex() }],
};
