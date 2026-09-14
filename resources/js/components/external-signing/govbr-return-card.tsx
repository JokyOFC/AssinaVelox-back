import {
    BadgeCheck,
    Clock,
    Download,
    ExternalLink,
    Landmark,
    RefreshCw,
    Upload,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import {
    CertificateFacts,
    TestCertificateNotice,
} from '@/components/certificates/certificate-facts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useIsMobile } from '@/hooks/use-mobile';
import { formatBytes, formatDateTime, formatTime, plural } from '@/lib/format';
import { govbrRequestTone, govbrReturnErrorTitles } from '@/lib/labels';
import { cn } from '@/lib/utils';
import { show as govbrShow } from '@/routes/sign/govbr';
import type {
    GovBrReturnRequest,
    GovBrReturnState,
    SignerJsonError,
} from '@/types/external-signing';
import { WithdrawControl } from './external-signing-card';
import { signerRequest, type SignerResponse } from './http';
import { CardShell, ErrorAlert, Note, StepList } from './parts';
import { ReauthNote } from './reauth-note';

type Alert = { title: string; message: string } | null;

/** Recusas que mudam o pedido (tentativas, reserva, conclusão): reconsulta o estado. */
const REFRESH_ON = new Set([
    'not_ready',
    'reservation_busy',
    'reservation_expired',
    'base_changed',
    'not_reserved',
    'already_completed',
    'envelope_closed',
    'window_closed',
    'cannot_withdraw',
    'not_authenticated',
    'not_found',
]);

/** Host legível de uma URL ("assinador.iti.br"). */
function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

/**
 * Assinar TAMBÉM no portal gov.br e devolver o PDF (Fase 3 §3.5 — docs/fase-3/gov-br.md §6).
 *
 * - Descoberto por `GET sign.govbr.show`: 404 (flag `govbr_return` desligada ou trava de
 *   integração, papel sem assinatura, convite encerrado) = o cartão não existe.
 * - A plataforma NÃO chama o gov.br (a API direta é classe C — bloqueada): o participante
 *   baixa a versão reservada, assina no portal oficial em outra aba e devolve o arquivo.
 * - Rótulo honesto (T1): o que o aceite gravaria HOJE vem do servidor (`trust.accepted_label`)
 *   — sem âncora configurada, "Assinatura digital de terceiro, cadeia não verificada", nunca
 *   "gov.br". O aviso correspondente (`notices`) é sempre exibido.
 * - Recusa: o motivo exato do servidor (`failure.message` / `message` do erro), com um título
 *   curto por código (não é o arquivo esperado, foi alterado, mais de uma assinatura,
 *   certificado de outra pessoa…).
 */
export function GovBrReturnCard({
    token,
    className,
    onCompleted,
    reauthAvailable = false,
}: {
    token: string;
    className?: string;
    onCompleted?: () => void;
    /** A página mostra o campo do código para reconfirmar a identidade (`otp` no comprovante). */
    reauthAvailable?: boolean;
}) {
    const showUrl = govbrShow(token).url;
    const ids = useId();
    const isMobile = useIsMobile();

    const [state, setState] = useState<GovBrReturnState | null>(null);
    const [probe, setProbe] = useState<'loading' | 'ready' | 'hidden'>(
        'loading',
    );
    const [loadError, setLoadError] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [alert, setAlert] = useState<Alert>(null);
    const [requestAlerts, setRequestAlerts] = useState<Record<string, Alert>>(
        {},
    );
    const [files, setFiles] = useState<Record<string, File | null>>({});
    const [invalidFile, setInvalidFile] = useState<Record<string, boolean>>({});
    const [confirmWithdraw, setConfirmWithdraw] = useState(false);

    const inputs = useRef<Record<string, HTMLInputElement | null>>({});
    const previousStage = useRef<string | null>(null);

    const refresh = useCallback(
        async (quiet = false) => {
            if (!quiet) {
                setBusy('refresh');
            }

            const response = await signerRequest<GovBrReturnState>(
                'GET',
                showUrl,
            );

            setBusy((value) => (value === 'refresh' ? null : value));

            if (response.status === 404) {
                setState(null);
                setProbe('hidden');

                return;
            }

            if (response.ok && response.body?.available) {
                setState(response.body);
                setProbe('ready');
                setLoadError(false);

                return;
            }

            setLoadError(true);
            setProbe((value) => (value === 'loading' ? 'ready' : value));
        },
        [showUrl],
    );

    useEffect(() => {
        void refresh(true);
    }, [refresh]);

    const stage = state?.stage ?? null;

    useEffect(() => {
        const before = previousStage.current;
        previousStage.current = stage;

        if (
            before !== null &&
            before !== 'completed' &&
            stage === 'completed'
        ) {
            onCompleted?.();
        }
    }, [stage, onCompleted]);

    if (probe !== 'ready') {
        return null;
    }

    if (!state) {
        return loadError ? (
            <Shell className={className}>
                <p className="text-text-secondary text-[12.5px]">
                    Não foi possível carregar a opção de assinar no gov.br.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="self-start"
                    onClick={() => void refresh()}
                    disabled={busy !== null}
                >
                    <RefreshCw className="size-[15px]" />
                    Tentar de novo
                </Button>
            </Shell>
        ) : null;
    }

    const requests = state.documents
        .map((item) => item.request)
        .filter((item): item is GovBrReturnRequest => item !== null);

    if (state.stage === 'closed' && requests.length === 0) {
        return null;
    }

    const multi = state.documents.length > 1;
    const portalHost = hostOf(state.portal_url);
    const maxBytes = state.limits.max_upload_mb * 1024 * 1024;

    // --- Erros do servidor ---------------------------------------------------

    const failureAlert = (
        response: SignerResponse<unknown>,
        fallback: string,
    ): Alert => {
        if (response.status === 429) {
            return {
                title: 'Muitas tentativas seguidas',
                message: `Aguarde ${response.retryAfter ?? 60} segundos e tente de novo.`,
            };
        }

        const body = response.body as SignerJsonError | null;

        if (response.network || !body || typeof body.message !== 'string') {
            return { title: 'Sem resposta do servidor', message: fallback };
        }

        const code = body.code ?? '';

        return {
            title:
                govbrReturnErrorTitles[code] ??
                (response.status === 422
                    ? 'Arquivo recusado'
                    : 'Não foi possível concluir'),
            message: body.message,
        };
    };

    const shouldRefresh = (response: SignerResponse<unknown>): boolean => {
        const code = (response.body as SignerJsonError | null)?.code ?? '';

        // 422 do arquivo também registra a tentativa no pedido (e o motivo em `failure`).
        return (
            response.network || response.status === 422 || REFRESH_ON.has(code)
        );
    };

    // --- Ações ---------------------------------------------------------------

    const post = async (
        key: string,
        url: string,
        fallback: string,
    ): Promise<void> => {
        setAlert(null);
        setBusy(key);
        const response = await signerRequest<GovBrReturnState>('POST', url);
        setBusy(null);

        if (response.ok && response.body?.available) {
            setState(response.body);
            setConfirmWithdraw(false);

            return;
        }

        setAlert(failureAlert(response, fallback));

        if (shouldRefresh(response)) {
            void refresh(true);
        }
    };

    const pickFile = (requestId: string, file: File | null) => {
        setFiles((current) => ({ ...current, [requestId]: file }));
        setInvalidFile((current) => ({ ...current, [requestId]: false }));
        setRequestAlerts((current) => ({ ...current, [requestId]: null }));
    };

    const upload = async (request: GovBrReturnRequest) => {
        const file = files[request.id] ?? null;
        const url = request.upload_url;
        const reject = (title: string, message: string) => {
            setInvalidFile((current) => ({ ...current, [request.id]: true }));
            setRequestAlerts((current) => ({
                ...current,
                [request.id]: { title, message },
            }));
        };

        if (!url) {
            return;
        }

        if (!file) {
            reject(
                'Escolha o arquivo assinado',
                'Selecione o PDF que o portal devolveu depois da assinatura.',
            );

            return;
        }

        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (!state.limits.accepted_extensions.includes(extension)) {
            reject(
                govbrReturnErrorTitles.not_pdf,
                'Envie o arquivo PDF que o portal devolveu, sem convertê-lo.',
            );

            return;
        }

        if (file.size === 0 || file.size > maxBytes) {
            reject(
                file.size === 0
                    ? 'Arquivo vazio'
                    : govbrReturnErrorTitles.file_too_large,
                file.size === 0
                    ? 'O arquivo escolhido está vazio. Escolha de novo o PDF assinado.'
                    : `O limite é ${formatBytes(maxBytes)}.`,
            );

            return;
        }

        const body = new FormData();
        body.append('file', file);

        setAlert(null);
        setRequestAlerts((current) => ({ ...current, [request.id]: null }));
        setBusy(`upload:${request.id}`);
        // A conferência roda no servidor (pdftool) e pode levar alguns minutos.
        const response = await signerRequest<GovBrReturnState>(
            'POST',
            url,
            body,
            { timeoutMs: 300000 },
        );
        setBusy(null);

        if (response.ok && response.body?.available) {
            setState(response.body);
            pickFile(request.id, null);

            if (inputs.current[request.id]) {
                inputs.current[request.id]!.value = '';
            }

            return;
        }

        setRequestAlerts((current) => ({
            ...current,
            [request.id]: failureAlert(
                response,
                'Não recebemos a confirmação do envio. Confira abaixo o estado atualizado antes de enviar de novo.',
            ),
        }));

        if (shouldRefresh(response)) {
            void refresh(true);
        }
    };

    // --- Partes da tela ------------------------------------------------------

    const stageBadge = (() => {
        switch (state.stage) {
            case 'awaiting_others':
                return <Badge variant="info">Escolha registrada</Badge>;
            case 'ready_to_reserve':
                return requests.length > 0 ? (
                    <Badge variant="warning">Pronto para reservar</Badge>
                ) : null;
            case 'reserved':
                return <Badge variant="warning">Versão reservada</Badge>;
            case 'completed':
                return <Badge variant="success">Arquivo recebido</Badge>;
            case 'expired':
                return <Badge variant="neutral">Prazo encerrado</Badge>;
            default:
                return null;
        }
    })();

    const notAuthenticated = !state.authenticated && (
        <ReauthNote
            token={token}
            message={state.message}
            available={reauthAvailable}
        />
    );

    const withdrawControl = state.can_withdraw && (
        <WithdrawControl
            open={confirmWithdraw}
            onOpenChange={setConfirmWithdraw}
            onConfirm={() =>
                void post(
                    'withdraw',
                    state.endpoints.withdraw,
                    'Não conseguimos registrar a desistência. Tente de novo.',
                )
            }
            busy={busy === 'withdraw'}
            disabled={busy !== null}
            question="Desistir de assinar no gov.br? O seu aceite eletrônico continua valendo."
            label="Desistir de assinar no gov.br"
        />
    );

    const resultLabel = (
        <p className="text-text-secondary text-[12.5px] leading-[1.5]">
            Se o arquivo devolvido for aceito, ele será registrado como:{' '}
            <b className="text-foreground">{state.trust.accepted_label}</b>.
        </p>
    );

    const noticeBlock = (
        <ul className="text-text-secondary flex list-disc flex-col gap-1 pl-4 text-[12.5px] leading-[1.5]">
            {state.notices.map((notice) => (
                <li key={notice}>{notice}</li>
            ))}
        </ul>
    );

    const documentPanels = (
        <ul className="flex flex-col gap-2.5">
            {state.documents.map(({ document, request }) => (
                <DocumentPanel
                    key={document.id}
                    ids={ids}
                    name={`${multi ? `${document.position}. ` : ''}${document.name?.trim() || `Arquivo ${document.position}`}`}
                    request={request}
                    portalUrl={state.portal_url}
                    portalHost={portalHost}
                    maxBytes={maxBytes}
                    file={request ? (files[request.id] ?? null) : null}
                    invalid={request ? invalidFile[request.id] === true : false}
                    alert={request ? (requestAlerts[request.id] ?? null) : null}
                    busy={busy}
                    registerInput={(element) => {
                        if (request) {
                            inputs.current[request.id] = element;
                        }
                    }}
                    onPick={(file) => request && pickFile(request.id, file)}
                    onUpload={() => request && void upload(request)}
                />
            ))}
        </ul>
    );

    // --- Corpo por estágio -----------------------------------------------------

    let body: ReactNode = null;

    switch (state.stage) {
        case 'choose':
            body = (
                <>
                    <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                        A versão para assinar no portal é liberada depois que
                        todos os participantes concluírem o aceite. Agora só a
                        sua escolha fica registrada.
                    </p>
                    {resultLabel}
                    {state.can_request && (
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full sm:w-auto sm:self-start"
                            onClick={() =>
                                void post(
                                    'intent',
                                    state.endpoints.intent,
                                    'Não conseguimos registrar a sua escolha. Tente de novo.',
                                )
                            }
                            disabled={busy !== null}
                        >
                            {busy === 'intent' ? (
                                <Spinner className="size-4" />
                            ) : (
                                <Landmark className="size-[15px]" />
                            )}
                            Quero assinar também no gov.br
                        </Button>
                    )}
                    {notAuthenticated}
                </>
            );
            break;

        case 'awaiting_others':
            body = (
                <>
                    <Note tone="info">
                        <b>Sua escolha está registrada.</b>{' '}
                        {state.message ??
                            'Volte por este link quando todos os participantes tiverem concluído o aceite.'}
                    </Note>
                    {notAuthenticated}
                    {withdrawControl}
                </>
            );
            break;

        case 'ready_to_reserve':
        case 'reserved':
            body = (
                <>
                    <div className="flex flex-col gap-2">
                        <p className="text-muted-foreground text-[11px] font-semibold tracking-[.08em] uppercase">
                            Passo a passo
                        </p>
                        <StepList steps={state.instructions} />
                    </div>
                    {resultLabel}
                    {state.stage === 'ready_to_reserve' &&
                        (state.can_reserve ? (
                            <Button
                                type="button"
                                className="w-full sm:w-auto sm:self-start"
                                onClick={() =>
                                    void post(
                                        'reserve',
                                        state.endpoints.reserve,
                                        'Não recebemos a confirmação da reserva. Confira abaixo o estado atualizado.',
                                    )
                                }
                                disabled={busy !== null}
                            >
                                {busy === 'reserve' ? (
                                    <Spinner className="size-4" />
                                ) : (
                                    <Landmark className="size-[15px]" />
                                )}
                                Reservar versão para assinar no gov.br
                            </Button>
                        ) : (
                            notAuthenticated
                        ))}
                    {requests.length > 0 && documentPanels}
                    {state.stage === 'reserved' && notAuthenticated}
                    {isMobile && (
                        <p className="text-muted-foreground text-[12px] leading-[1.5]">
                            No celular, o arquivo baixado vai para a pasta de
                            downloads: escolha-o de lá no portal e, depois,
                            escolha aqui o arquivo que o portal devolver.
                        </p>
                    )}
                    {withdrawControl}
                </>
            );
            break;

        case 'completed':
            body = (
                <>
                    {documentPanels}
                    <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                        A assinatura se soma ao seu aceite eletrônico, que
                        continua valendo.
                    </p>
                </>
            );
            break;

        case 'expired':
            body = (
                <>
                    <Note tone="neutral">
                        O prazo para devolver o arquivo assinado no portal
                        terminou. O seu aceite eletrônico continua valendo.
                    </Note>
                    {requests.length > 0 && documentPanels}
                </>
            );
            break;

        default:
            body = (
                <>
                    <Note tone="neutral">
                        {state.message ??
                            'Este documento não está mais recebendo assinaturas pelo portal.'}
                    </Note>
                    {requests.length > 0 && documentPanels}
                </>
            );
    }

    return (
        <Shell className={className} badge={stageBadge}>
            <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                Além do seu aceite eletrônico, você pode assinar o arquivo no{' '}
                <b className="text-foreground">portal oficial do gov.br</b> (
                {portalHost}) com a sua conta gov.br e devolvê-lo aqui. A
                plataforma não acessa a sua conta: você baixa a versão
                preparada, assina no portal em outra aba e envia o arquivo que o
                portal devolver.
            </p>

            {!state.trust.anchors_configured ? (
                <Note tone="warning" role="note">
                    {state.notices.find((notice) =>
                        notice.includes('cadeia não verificada'),
                    ) ??
                        `Esta plataforma ainda não verifica a cadeia de certificados do gov.br: o arquivo aceito será registrado como “${state.trust.accepted_label}”.`}
                </Note>
            ) : null}

            <details className="text-[12.5px] leading-[1.5]">
                <summary className="text-primary cursor-pointer font-semibold">
                    O que é e o que não é
                </summary>
                <div className="mt-2 flex flex-col gap-2">
                    {noticeBlock}
                    <p className="text-text-secondary">
                        Só é aceito o arquivo que for exatamente a versão
                        baixada aqui com uma única assinatura acrescentada. A
                        revogação do certificado não é consultada. Prazo total
                        para devolver:{' '}
                        {plural(
                            Math.round(
                                state.limits.application_window_minutes / 60,
                            ),
                            'hora',
                        )}
                        .
                    </p>
                </div>
            </details>

            {body}

            {alert && (
                <ErrorAlert title={alert.title} message={alert.message} />
            )}
        </Shell>
    );
}

// ---------------------------------------------------------------------------

function Shell({
    children,
    badge,
    className,
}: {
    children: ReactNode;
    badge?: ReactNode;
    className?: string;
}) {
    return (
        <CardShell
            icon={Landmark}
            label="Assinatura no portal gov.br"
            title="Assinar também no portal gov.br"
            subtitle="Opcional · você assina no portal oficial e devolve o PDF"
            badge={badge}
            className={className}
        >
            {children}
        </CardShell>
    );
}

function DocumentPanel({
    ids,
    name,
    request,
    portalUrl,
    portalHost,
    maxBytes,
    file,
    invalid,
    alert,
    busy,
    registerInput,
    onPick,
    onUpload,
}: {
    ids: string;
    name: string;
    request: GovBrReturnRequest | null;
    portalUrl: string;
    portalHost: string;
    maxBytes: number;
    file: File | null;
    invalid: boolean;
    alert: Alert;
    busy: string | null;
    registerInput: (element: HTMLInputElement | null) => void;
    onPick: (file: File | null) => void;
    onUpload: () => void;
}) {
    if (!request) {
        return null;
    }

    const inputId = `${ids}-${request.id}`;
    const reserved =
        request.status === 'pending' && request.expected_revision !== null;
    const signature = request.signature;
    const uploading = busy === `upload:${request.id}`;

    return (
        <li className="border-border flex flex-col gap-3 rounded-[10px] border p-3.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="min-w-0 text-[13px] font-semibold">
                    {name}
                </span>
                <Badge variant={govbrRequestTone(request.status)}>
                    {request.status_label}
                </Badge>
            </div>

            {reserved && request.expected_revision && (
                <>
                    {request.expires_at && (
                        <p className="text-text-secondary flex items-start gap-1.5 text-[12.5px]">
                            <Clock className="mt-0.5 size-3.5 shrink-0" />
                            <span>
                                Versão reservada até{' '}
                                <b className="tabular">
                                    {formatTime(request.expires_at)}
                                </b>
                                . Depois disso, reserve de novo.
                            </span>
                        </p>
                    )}

                    <ol className="flex flex-col gap-3">
                        <li className="flex flex-col gap-1.5">
                            <p className="text-[12.5px] font-semibold">
                                1. Baixe o arquivo preparado
                            </p>
                            {request.expected_revision.download_url ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full sm:w-auto sm:self-start"
                                >
                                    <a
                                        href={
                                            request.expected_revision
                                                .download_url
                                        }
                                        download
                                    >
                                        <Download className="size-[15px]" />
                                        Baixar arquivo para assinar
                                    </a>
                                </Button>
                            ) : (
                                <p className="text-text-secondary text-[12px]">
                                    Confirme sua identidade para baixar o
                                    arquivo.
                                </p>
                            )}
                            <p className="text-muted-foreground text-[11.5px] leading-[1.45]">
                                {formatBytes(
                                    request.expected_revision.size_bytes,
                                )}{' '}
                                · SHA-256{' '}
                                <span className="font-mono break-all">
                                    {request.expected_revision.sha256}
                                </span>
                            </p>
                        </li>
                        <li className="flex flex-col gap-1.5">
                            <p className="text-[12.5px] font-semibold">
                                2. Assine no portal oficial, em outra aba
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                className="w-full sm:w-auto sm:self-start"
                            >
                                <a
                                    href={portalUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ExternalLink className="size-[15px]" />
                                    Abrir {portalHost}
                                </a>
                            </Button>
                            <p className="text-muted-foreground text-[11.5px] leading-[1.45]">
                                Entre com a sua conta gov.br (nível prata ou
                                ouro), escolha o arquivo baixado e assine sem
                                editar, converter ou salvar em outro programa.
                            </p>
                        </li>
                        <li className="flex flex-col gap-1.5">
                            <p className="text-[12.5px] font-semibold">
                                3. Volte e envie o arquivo assinado
                            </p>
                            <input
                                ref={registerInput}
                                id={inputId}
                                type="file"
                                accept=".pdf,application/pdf"
                                className="peer sr-only"
                                aria-invalid={invalid}
                                onChange={(event) =>
                                    onPick(event.target.files?.[0] ?? null)
                                }
                            />
                            <label
                                htmlFor={inputId}
                                className={cn(
                                    'border-border-dashed text-text-secondary hover:border-primary hover:bg-accent-subtle peer-focus-visible:border-primary peer-focus-visible:ring-ring/50 flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] border border-dashed px-3 py-2.5 text-[13px] peer-focus-visible:ring-[3px]',
                                    invalid && 'border-danger',
                                )}
                            >
                                <Upload className="size-4 shrink-0" />
                                <span className="min-w-0 truncate">
                                    {file
                                        ? `${file.name} · ${formatBytes(file.size)}`
                                        : 'Escolher o PDF assinado'}
                                </span>
                            </label>
                            <p className="text-muted-foreground text-[11.5px]">
                                PDF devolvido pelo portal, até{' '}
                                {formatBytes(maxBytes)}.
                            </p>
                            <Button
                                type="button"
                                className="w-full sm:w-auto sm:self-start"
                                onClick={onUpload}
                                disabled={
                                    busy !== null ||
                                    !file ||
                                    request.upload_url === null
                                }
                            >
                                {uploading ? (
                                    <Spinner className="size-4" />
                                ) : (
                                    <Upload className="size-[15px]" />
                                )}
                                Enviar arquivo assinado
                            </Button>
                            {uploading && (
                                <p className="text-text-secondary text-[12px] leading-[1.5]">
                                    Conferindo o arquivo: a plataforma verifica
                                    se ele é a versão entregue com uma única
                                    assinatura acrescentada. Isso pode levar
                                    alguns minutos.
                                </p>
                            )}
                        </li>
                    </ol>
                </>
            )}

            {request.status === 'requested' && (
                <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                    Assim que a versão for reservada, o arquivo para assinar
                    aparece aqui.
                </p>
            )}

            {request.failure?.message && request.status !== 'completed' && (
                <Note tone={reserved ? 'danger' : 'neutral'} role="note">
                    <b>
                        {govbrReturnErrorTitles[request.failure.code] ??
                            'Último envio não aceito'}
                        .
                    </b>{' '}
                    {request.failure.message}
                    {request.attempts > 0 && (
                        <span className="mt-1 block">
                            {plural(
                                request.attempts,
                                'envio recebido',
                                'envios recebidos',
                            )}{' '}
                            para este arquivo.
                        </span>
                    )}
                </Note>
            )}

            {alert && (
                <ErrorAlert title={alert.title} message={alert.message} />
            )}

            {request.status === 'completed' && signature && (
                <div className="flex flex-col gap-2.5">
                    <Note
                        tone={signature.trusted ? 'success' : 'neutral'}
                        icon={<BadgeCheck className="size-4" />}
                    >
                        <b>{signature.label}</b>
                        <span className="mt-1 block">
                            {signature.description}
                        </span>
                        {signature.signed_at && (
                            <span className="mt-1 block">
                                Recebido em{' '}
                                {formatDateTime(signature.signed_at)}.
                            </span>
                        )}
                    </Note>
                    {signature.is_test && <TestCertificateNotice />}
                    <CertificateFacts
                        certificate={{
                            holder_name: signature.holder_name,
                            holder_cpf_masked: signature.holder_cpf_masked,
                            issuer: signature.issuer,
                            valid_from: signature.valid_from,
                            valid_to: signature.valid_to,
                            fingerprint_sha256: signature.fingerprint_sha256,
                        }}
                        showSerial={false}
                    />
                </div>
            )}
        </li>
    );
}
