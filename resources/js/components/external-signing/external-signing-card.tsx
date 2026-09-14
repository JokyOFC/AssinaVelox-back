import {
    BadgeCheck,
    Clock,
    KeyRound,
    Laptop,
    RefreshCw,
    ShieldQuestion,
    Usb,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
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
import { formatDateTime, formatTime, plural } from '@/lib/format';
import {
    externalDocumentStatusLabels,
    externalDocumentStatusTones,
    externalSigningErrorTitles,
    participantRequestTone,
} from '@/lib/labels';
import { cn } from '@/lib/utils';
import { show as externalShow } from '@/routes/sign/external';
import type {
    ExternalPending,
    ExternalPrepareResponse,
    ExternalSigningDocument,
    ExternalSigningState,
    SignerJsonError,
    SimulatorCertificateResponse,
} from '@/types/external-signing';
import { signerRequest, type SignerResponse } from './http';
import {
    LocalSignerError,
    createNexuBridge,
    type LocalSignerBridge,
    type LocalSignerDetection,
} from './local-signer-bridge';
import { CardShell, ErrorAlert, Note, SimulatedTag, StepList } from './parts';
import { ReauthNote } from './reauth-note';

type Choice = 'simulated' | 'nexu';
type Alert = { title: string; message: string } | null;

/** Certificado público devolvido pelo componente (ou pelo simulador). Sem chave. */
interface ComponentCertificate {
    certificate: string;
    chain: string[];
}

/** Motivos de indisponibilidade (`LocalSignerStatus::reason`) em PT-BR. */
const COMPONENT_REASONS: Record<string, string> = {
    production_disabled:
        'O uso com token real ainda não foi habilitado nesta plataforma.',
    environment_not_allowed:
        'O simulador só funciona em ambiente de teste ou local.',
    disabled: 'Desligado nesta plataforma.',
    not_configured: 'Não configurado nesta plataforma.',
    pfx_missing: 'O certificado de teste do simulador não está configurado.',
    pfx_unreadable: 'O certificado de teste do simulador não pôde ser aberto.',
};

/** Recusas depois das quais a reserva do servidor não serve mais (§6: consumo único). */
const PENDING_GONE = new Set([
    'expired',
    'already_consumed',
    'stale_revision',
    'pending_closed',
    'pending_missing',
    'pending_not_found',
    'signature_invalid',
    'certificate_mismatch',
    'digest_mismatch',
    'cms_invalid',
    'cms_too_large',
    'embed_failed',
    'simulator_only',
    'not_simulated',
]);

/** Recusas que mudam o que a tela deve mostrar: reconsulta o estado. */
const REFRESH_ON = new Set([
    ...PENDING_GONE,
    'not_ready',
    'document_reserved',
    'waiting_base',
    'already_signed',
    'request_closed',
    'envelope_closed',
    'window_closed',
    'other_method_chosen',
    'not_authenticated',
    'cannot_withdraw',
    'not_requested',
]);

/**
 * Assinar TAMBÉM com certificado em token ou cartão (A3), por componente instalado no
 * computador do participante (Fase 3 §3.4 — docs/fase-3/assinatura-externa-a3.md §7).
 *
 * - Descoberto por `GET sign.external.show`: 404 (flag `a3_signing` desligada, papel sem
 *   assinatura, envelope encerrado) = o cartão não existe e a página é a de antes.
 * - Opção SEPARADA do aceite eletrônico, que continua obrigatório e valendo (T1).
 * - A chave nunca passa pela plataforma: o componente devolve o certificado (público), a
 *   plataforma prepara o resumo (`pending.digest`, só na resposta de `prepare`) e o componente
 *   devolve a assinatura. O PIN é digitado na janela do componente, nunca nesta página. Resumo
 *   e identificador da chave ficam só na memória deste cartão.
 * - Simulador (teste/local): assina NO SERVIDOR com o resumo da própria reserva e é sempre
 *   rotulado "simulado — nenhum token foi usado". Nunca aparece como token real.
 * - Componente real (NexU): usado só quando o servidor diz que está habilitado; hoje a produção
 *   está desabilitada e a página nem tenta a rede local.
 */
export function ExternalSigningCard({
    token,
    className,
    onApplied,
    reauthAvailable = false,
}: {
    token: string;
    className?: string;
    /** Chamado quando a assinatura passa a "aplicada" (para recarregar o comprovante). */
    onApplied?: () => void;
    /** A página mostra o campo do código para reconfirmar a identidade (`otp` no comprovante). */
    reauthAvailable?: boolean;
}) {
    const showUrl = externalShow(token).url;
    const isMobile = useIsMobile();

    const [state, setState] = useState<ExternalSigningState | null>(null);
    const [probe, setProbe] = useState<'loading' | 'ready' | 'hidden'>(
        'loading',
    );
    const [loadError, setLoadError] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [alert, setAlert] = useState<Alert>(null);
    const [expanded, setExpanded] = useState(false);
    const [confirmWithdraw, setConfirmWithdraw] = useState(false);

    const [choice, setChoice] = useState<Choice | null>(null);
    const [detection, setDetection] = useState<LocalSignerDetection | null>(
        null,
    );
    const [componentCert, setComponentCert] =
        useState<ComponentCertificate | null>(null);
    // Reservas preparadas NESTA página, com o resumo (só em memória).
    const [prepared, setPrepared] = useState<Record<string, ExternalPending>>(
        {},
    );
    const [, setTick] = useState(0);

    const keyHandle = useRef<unknown>(null);
    const bridge = useRef<LocalSignerBridge | null>(null);
    const previousStage = useRef<string | null>(null);

    const forgetComponent = useCallback(() => {
        keyHandle.current = null;
        setComponentCert(null);
        setPrepared({});
    }, []);

    const refresh = useCallback(
        async (quiet = false) => {
            if (!quiet) {
                setBusy('refresh');
            }

            const response = await signerRequest<ExternalSigningState>(
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

        if (before !== null && before !== 'applied' && stage === 'applied') {
            forgetComponent();
            onApplied?.();
        }
    }, [stage, onApplied, forgetComponent]);

    // Relógio para o vencimento dos resumos preparados.
    const hasPending =
        state?.documents.some((doc) => doc.pending !== null) ?? false;

    useEffect(() => {
        if (!hasPending) {
            return;
        }

        const timer = window.setInterval(
            () => setTick((value) => value + 1),
            15000,
        );

        return () => window.clearInterval(timer);
    }, [hasPending]);

    if (probe !== 'ready') {
        return null;
    }

    if (!state) {
        return loadError ? (
            <Shell className={className}>
                <p className="text-text-secondary text-[12.5px]">
                    Não foi possível carregar a opção de assinar com certificado
                    em token.
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

    if (state.stage === 'closed' && state.request === null) {
        return null;
    }

    const request = state.request;
    const simulator = state.components.find(
        (item) => item.component === 'simulated',
    );
    const nexu = state.components.find((item) => item.component === 'nexu');
    const simulatorAvailable =
        state.endpoints.simulator_sign !== null &&
        state.endpoints.simulator_certificate !== null &&
        simulator?.available === true;
    const nexuEnabled =
        nexu?.available === true &&
        nexu.production_enabled &&
        state.local_component.production_enabled;

    // --- Erros do servidor ---------------------------------------------------

    const handleFailure = (
        response: SignerResponse<unknown>,
        fallback: string,
        documentId?: string,
    ) => {
        if (response.status === 429) {
            setAlert({
                title: 'Muitas tentativas seguidas',
                message: `Aguarde ${response.retryAfter ?? 60} segundos e tente de novo.`,
            });

            return;
        }

        const body = response.body as SignerJsonError | null;

        if (response.network || !body || typeof body.message !== 'string') {
            // T5: sem confirmação, nada é afirmado — consulta o estado real.
            setAlert({ title: 'Sem resposta do servidor', message: fallback });
            void refresh(true);

            return;
        }

        const code = body.code ?? '';

        setAlert({
            title:
                externalSigningErrorTitles[code] ??
                (response.status === 422
                    ? 'Confira os dados enviados'
                    : 'Não foi possível concluir'),
            message: body.message,
        });

        if (documentId && PENDING_GONE.has(code)) {
            setPrepared((current) => {
                const next = { ...current };
                delete next[documentId];

                return next;
            });
        }

        if (REFRESH_ON.has(code)) {
            void refresh(true);
        }
    };

    const localFailure = (exception: unknown) => {
        setAlert(
            exception instanceof LocalSignerError
                ? {
                      title:
                          exception.code === 'operation_failed'
                              ? 'O token não concluiu a operação'
                              : exception.code === 'token_not_found'
                                ? 'Token ou cartão não encontrado'
                                : exception.code === 'component_unreachable'
                                  ? 'Componente não encontrado'
                                  : 'Componente de assinatura',
                      message: exception.message,
                  }
                : {
                      title: 'Componente de assinatura',
                      message:
                          'O componente não respondeu como esperado. Nada foi enviado à plataforma.',
                  },
        );
    };

    const resetMessages = () => setAlert(null);

    // --- Ações ---------------------------------------------------------------

    const post = async <T,>(
        key: string,
        url: string,
        body: Record<string, unknown> = {},
        timeoutMs?: number,
    ): Promise<SignerResponse<T>> => {
        resetMessages();
        setBusy(key);
        const response = await signerRequest<T>('POST', url, body, {
            timeoutMs,
        });
        setBusy(null);

        return response;
    };

    const requestIntent = async () => {
        const response = await post<ExternalSigningState>(
            'intent',
            state.endpoints.intent,
        );

        if (response.ok && response.body?.available) {
            setState(response.body);
            setExpanded(true);

            return;
        }

        handleFailure(
            response,
            'Não conseguimos registrar a sua escolha. Tente de novo.',
        );
    };

    const withdraw = async () => {
        const response = await post<ExternalSigningState>(
            'withdraw',
            state.endpoints.withdraw,
        );
        setConfirmWithdraw(false);

        if (response.ok && response.body?.available) {
            setState(response.body);
            setExpanded(false);
            forgetComponent();

            return;
        }

        handleFailure(
            response,
            'Não conseguimos registrar a desistência. Tente de novo.',
        );
    };

    const choose = (next: Choice) => {
        if (next === choice) {
            return;
        }

        setChoice(next);
        setDetection(null);
        forgetComponent();
        resetMessages();
        bridge.current =
            next === 'nexu' ? createNexuBridge(state.local_component) : null;
    };

    const detect = async () => {
        if (!bridge.current) {
            return;
        }

        resetMessages();
        setBusy('detect');
        const result = await bridge.current.detect();
        setBusy(null);
        setDetection(result);
    };

    const readCertificate = async () => {
        resetMessages();

        if (choice === 'simulated' && state.endpoints.simulator_certificate) {
            setBusy('certificate');
            const response = await signerRequest<SimulatorCertificateResponse>(
                'GET',
                state.endpoints.simulator_certificate,
            );
            setBusy(null);

            if (response.ok && response.body?.certificate) {
                setComponentCert({
                    certificate: response.body.certificate,
                    chain: response.body.certificate_chain ?? [],
                });

                return;
            }

            handleFailure(
                response,
                'Não conseguimos obter o certificado do simulador.',
            );

            return;
        }

        if (choice === 'nexu' && bridge.current) {
            setBusy('certificate');

            try {
                const certificate =
                    await bridge.current.getSigningCertificate();
                keyHandle.current = certificate.keyHandle;
                setComponentCert({
                    certificate: certificate.certificate,
                    chain: certificate.chain,
                });
            } catch (exception) {
                localFailure(exception);
            } finally {
                setBusy(null);
            }
        }
    };

    const prepare = async (doc: ExternalSigningDocument) => {
        if (!choice || !componentCert) {
            return;
        }

        const response = await post<ExternalPrepareResponse>(
            `prepare:${doc.id}`,
            state.endpoints.prepare,
            {
                document_id: doc.id,
                component: choice,
                mode: 'raw',
                certificate: componentCert.certificate,
                chain: componentCert.chain.slice(
                    0,
                    state.limits.max_chain_certificates,
                ),
            },
            60000,
        );

        if (response.ok && response.body?.pending) {
            const pending = response.body.pending;
            setPrepared((current) => ({ ...current, [doc.id]: pending }));
            setState(response.body.state);

            return;
        }

        handleFailure(
            response,
            'Não recebemos a preparação do resumo. Confira a conexão e tente de novo.',
            doc.id,
        );
    };

    const sign = async (doc: ExternalSigningDocument) => {
        const pending = prepared[doc.id] ?? doc.pending;

        if (!pending) {
            return;
        }

        const dropPending = () =>
            setPrepared((current) => {
                const next = { ...current };
                delete next[doc.id];

                return next;
            });

        // Simulador: o servidor assina com o resumo da PRÓPRIA reserva (nunca vindo daqui).
        if (pending.simulated) {
            const url =
                pending.endpoints.simulate ?? state.endpoints.simulator_sign;

            if (!url) {
                return;
            }

            const response = await post<ExternalSigningState>(
                `sign:${doc.id}`,
                url,
                { pending_id: pending.id },
                120000,
            );

            if (response.ok && response.body?.available) {
                dropPending();
                setState(response.body);

                return;
            }

            handleFailure(
                response,
                'Não recebemos a confirmação da assinatura. Confira abaixo o estado atualizado.',
                doc.id,
            );

            return;
        }

        if (
            !bridge.current ||
            !pending.digest ||
            !componentCert ||
            !pending.endpoints.submit
        ) {
            return;
        }

        resetMessages();
        setBusy(`sign:${doc.id}`);
        let result;

        try {
            // O componente abre a janela do PIN; a página só espera a assinatura do resumo.
            result = await bridge.current.signDigest(
                keyHandle.current,
                pending.digest,
                'SHA256',
            );
        } catch (exception) {
            setBusy(null);
            localFailure(exception);

            return;
        }

        const response = await signerRequest<ExternalSigningState>(
            'POST',
            pending.endpoints.submit,
            {
                pending_id: pending.id,
                mode: 'raw',
                signature: result.signature,
                certificate: result.certificate ?? componentCert.certificate,
                chain: (result.chain.length > 0
                    ? result.chain
                    : componentCert.chain
                ).slice(0, state.limits.max_chain_certificates),
                ...(result.signatureAlgorithm
                    ? { signature_algorithm: result.signatureAlgorithm }
                    : {}),
            },
            { timeoutMs: 120000 },
        );
        setBusy(null);
        // O resumo foi usado (ou recusado): some da memória em qualquer caso.
        dropPending();

        if (response.ok && response.body?.available) {
            setState(response.body);

            return;
        }

        handleFailure(
            response,
            'Não recebemos a confirmação da assinatura. Confira abaixo o estado atualizado antes de tentar de novo.',
            doc.id,
        );
    };

    // --- Partes da tela ------------------------------------------------------

    const stageBadge = (() => {
        if (state.stage === 'applied' && request) {
            return (
                <Badge variant={participantRequestTone(request.status)}>
                    {request.status_label}
                </Badge>
            );
        }

        if (state.stage === 'awaiting_others') {
            return <Badge variant="info">Escolha registrada</Badge>;
        }

        if (state.stage === 'ready_to_sign' && request) {
            return <Badge variant="warning">Aguardando sua assinatura</Badge>;
        }

        return request ? (
            <Badge variant={participantRequestTone(request.status)}>
                {request.status_label}
            </Badge>
        ) : null;
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
            onConfirm={() => void withdraw()}
            busy={busy === 'withdraw'}
            disabled={busy !== null}
            question="Desistir de assinar com certificado em token? O seu aceite eletrônico continua valendo."
            label="Desistir de assinar com certificado em token"
        />
    );

    const componentPicker = (
        <div className="flex flex-col gap-2.5">
            <SectionTitle>1. Componente de assinatura</SectionTitle>

            {isMobile && (
                <Note tone="warning" icon={<Laptop className="size-4" />}>
                    A assinatura com token ou cartão (A3) exige um computador
                    com o componente de assinatura instalado e o token
                    conectado. Abra este mesmo link no computador para
                    continuar.
                </Note>
            )}

            <div className="grid gap-2">
                {simulatorAvailable && (
                    <ChoiceButton
                        selected={choice === 'simulated'}
                        onClick={() => choose('simulated')}
                        title={
                            <>
                                Simulador de componente{' '}
                                <SimulatedTag className="ml-1" />
                            </>
                        }
                        description="Ambiente de teste: nenhum token é usado e a assinatura não tem valor para uso real."
                    />
                )}
                {nexu && (
                    <ChoiceButton
                        selected={choice === 'nexu'}
                        onClick={() => choose('nexu')}
                        disabled={!nexuEnabled || isMobile}
                        title="Token ou cartão neste computador"
                        description={
                            nexuEnabled
                                ? `${nexu.label}${nexu.version ? ` · versão ${nexu.version}` : ''}`
                                : (COMPONENT_REASONS[nexu.reason ?? ''] ??
                                  'Ainda não disponível nesta plataforma.')
                        }
                    />
                )}
            </div>

            {!nexuEnabled && !simulatorAvailable && (
                <Note tone="neutral">
                    Nenhum componente de assinatura está disponível nesta
                    plataforma no momento. Você pode concluir o documento só com
                    o aceite eletrônico.
                </Note>
            )}

            {choice === 'nexu' && (
                <div className="border-border flex flex-col gap-2.5 rounded-[10px] border p-3.5">
                    <p className="text-[12.5px] font-semibold">
                        Antes de verificar
                    </p>
                    <StepList
                        steps={[
                            `Instale o componente de assinatura (versão ${state.local_component.minimum_version} ou mais recente) e deixe-o aberto.`,
                            'Conecte o token ou insira o cartão no leitor.',
                            ...state.local_component.browser_notes,
                        ]}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full sm:w-auto sm:self-start"
                        onClick={() => void detect()}
                        disabled={busy !== null}
                    >
                        {busy === 'detect' ? (
                            <Spinner className="size-4" />
                        ) : (
                            <Usb className="size-[15px]" />
                        )}
                        Verificar componente
                    </Button>
                    {detection?.available && (
                        <Note
                            tone="success"
                            icon={<BadgeCheck className="size-4" />}
                            role="status"
                        >
                            Componente encontrado
                            {detection.version
                                ? ` · versão ${detection.version}`
                                : ''}
                            .
                        </Note>
                    )}
                    {detection && !detection.available && (
                        <Note
                            tone="danger"
                            icon={<ShieldQuestion className="size-4" />}
                            role="alert"
                        >
                            <b>Componente não encontrado.</b>{' '}
                            {detection.error?.message}
                        </Note>
                    )}
                </div>
            )}
        </div>
    );

    const certificateStep = choice !== null &&
        (choice === 'simulated' || detection?.available === true) && (
            <div className="border-border flex flex-col gap-2.5 border-t pt-3.5">
                <SectionTitle>2. Certificado</SectionTitle>
                {componentCert ? (
                    <Note
                        tone="success"
                        icon={<BadgeCheck className="size-4" />}
                        role="status"
                    >
                        {choice === 'simulated'
                            ? 'Certificado de teste do simulador obtido.'
                            : 'Certificado lido pelo componente.'}{' '}
                        Os dados aparecem abaixo depois que a plataforma
                        preparar o resumo, lidos pelo servidor.
                    </Note>
                ) : (
                    <>
                        <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                            {choice === 'simulated'
                                ? 'O simulador usa um certificado de TESTE guardado no servidor. Nenhum token é usado.'
                                : 'O componente pede para você escolher o certificado do token. Só o certificado (público) vem para esta página — a chave continua no token.'}
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full sm:w-auto sm:self-start"
                            onClick={() => void readCertificate()}
                            disabled={busy !== null}
                        >
                            {busy === 'certificate' ? (
                                <Spinner className="size-4" />
                            ) : (
                                <KeyRound className="size-[15px]" />
                            )}
                            {choice === 'simulated'
                                ? 'Usar o certificado do simulador'
                                : 'Ler certificado do token'}
                        </Button>
                    </>
                )}
            </div>
        );

    const documentsStep = state.documents.length > 0 && (
        <div className="border-border flex flex-col gap-2.5 border-t pt-3.5">
            <SectionTitle>
                {choice ? '3. ' : ''}
                {state.documents.length > 1 ? 'Arquivos' : 'Arquivo'}
            </SectionTitle>
            <ul className="flex flex-col gap-2.5">
                {state.documents.map((doc) => (
                    <DocumentRow
                        key={doc.id}
                        doc={doc}
                        multi={state.documents.length > 1}
                        pending={prepared[doc.id] ?? doc.pending}
                        canPrepare={
                            state.can_prepare &&
                            choice !== null &&
                            componentCert !== null
                        }
                        choice={choice}
                        busy={busy}
                        onPrepare={() => void prepare(doc)}
                        onSign={() => void sign(doc)}
                    />
                ))}
            </ul>
            <p className="text-muted-foreground text-[11.5px] leading-[1.45]">
                O resumo preparado vale{' '}
                {plural(state.limits.pending_ttl_minutes, 'minuto')} e só pode
                ser usado uma vez. Se o prazo acabar, prepare de novo.
            </p>
        </div>
    );

    // --- Corpo por estágio -----------------------------------------------------

    let body: ReactNode = null;

    switch (state.stage) {
        case 'choose':
            body = (
                <>
                    <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                        A assinatura com o token acontece depois que todos os
                        participantes concluírem o aceite, porque ela cobre o
                        arquivo já consolidado. Agora só a sua escolha fica
                        registrada.
                    </p>
                    {state.can_request && (
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full sm:w-auto sm:self-start"
                            onClick={() => void requestIntent()}
                            disabled={busy !== null}
                        >
                            {busy === 'intent' ? (
                                <Spinner className="size-4" />
                            ) : (
                                <Usb className="size-[15px]" />
                            )}
                            Quero assinar também com certificado em token
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
                            'Volte por este link quando todos os participantes tiverem concluído o aceite para assinar com o token.'}
                    </Note>
                    {notAuthenticated}
                    {withdrawControl}
                </>
            );
            break;

        case 'ready_to_sign':
            body =
                request !== null || expanded ? (
                    <>
                        {request?.window_expires_at && (
                            <p className="text-text-secondary flex items-start gap-1.5 text-[12.5px]">
                                <Clock className="mt-0.5 size-3.5 shrink-0" />
                                <span>
                                    Prazo para assinar com o token:{' '}
                                    <b className="tabular">
                                        {formatDateTime(
                                            request.window_expires_at,
                                        )}
                                    </b>
                                    .
                                </span>
                            </p>
                        )}
                        {state.can_prepare ? (
                            <>
                                {componentPicker}
                                {certificateStep}
                                {documentsStep}
                            </>
                        ) : (
                            (notAuthenticated ??
                            (state.message ? (
                                <Note tone="neutral">{state.message}</Note>
                            ) : null))
                        )}
                        {withdrawControl}
                    </>
                ) : (
                    <>
                        <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                            O arquivo está pronto. Se quiser, acrescente uma
                            assinatura com o certificado do seu token ou cartão.
                        </p>
                        {state.can_prepare ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full sm:w-auto sm:self-start"
                                onClick={() => setExpanded(true)}
                            >
                                <Usb className="size-[15px]" />
                                Assinar também com certificado em token
                            </Button>
                        ) : (
                            notAuthenticated
                        )}
                    </>
                );
            break;

        case 'applied':
            body = (
                <>
                    <Note
                        tone="success"
                        icon={<BadgeCheck className="size-4" />}
                    >
                        <b>
                            {request?.label ??
                                'Assinatura com certificado aplicada.'}
                        </b>
                        {request?.kind_label && (
                            <span className="mt-1 block">
                                {request.kind_label}
                            </span>
                        )}
                        {request?.applied_at && (
                            <span className="mt-1 block">
                                Aplicada em {formatDateTime(request.applied_at)}
                                {request.documents_signed > 0 &&
                                    ` · ${plural(request.documents_signed, 'arquivo assinado', 'arquivos assinados')}`}
                                .
                            </span>
                        )}
                        <span className="mt-1 block">
                            A assinatura aparece no arquivo final e na página de
                            verificação, ao lado do seu aceite eletrônico.
                        </span>
                    </Note>
                    {request?.component === 'simulated' && (
                        <Note tone="warning">
                            <SimulatedTag /> Esta assinatura foi feita pelo
                            simulador: nenhum token foi usado e ela não tem
                            valor para uso real.
                        </Note>
                    )}
                </>
            );
            break;

        case 'expired':
            body = (
                <Note tone="neutral">
                    O prazo para assinar com o token terminou
                    {request?.window_expires_at
                        ? ` em ${formatDateTime(request.window_expires_at)}`
                        : ''}
                    . O documento foi concluído sem esta assinatura; o seu
                    aceite eletrônico continua valendo.
                </Note>
            );
            break;

        case 'withdrawn':
            body = (
                <>
                    <Note tone="neutral">
                        Você desistiu de assinar com certificado em token. O seu
                        aceite eletrônico continua valendo.
                    </Note>
                    {state.can_request && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="self-start"
                            onClick={() => void requestIntent()}
                            disabled={busy !== null}
                        >
                            {busy === 'intent' && (
                                <Spinner className="size-3.5" />
                            )}
                            Quero assinar com certificado em token
                        </Button>
                    )}
                </>
            );
            break;

        case 'other_method':
            body = (
                <Note tone="neutral">
                    {state.message ??
                        'Você já escolheu outro meio de assinatura com certificado neste documento.'}
                </Note>
            );
            break;

        default:
            body = (
                <Note tone="neutral">
                    {state.message ??
                        'Este documento não está mais recebendo assinaturas com certificado em token.'}
                </Note>
            );
    }

    return (
        <Shell className={className} badge={stageBadge}>
            <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                Além do seu aceite eletrônico, você pode acrescentar ao arquivo
                final uma{' '}
                <b className="text-foreground">
                    assinatura criptográfica feita com o certificado do seu
                    token ou cartão
                </b>
                . A chave não sai do token: a plataforma entrega um resumo do
                documento, o componente instalado no seu computador o assina e
                devolve só a assinatura. O PIN é digitado na janela do
                componente, nunca nesta página.
            </p>

            <details className="text-[12.5px] leading-[1.5]">
                <summary className="text-primary cursor-pointer font-semibold">
                    O que é e o que não é
                </summary>
                <ul className="text-text-secondary mt-2 flex list-disc flex-col gap-1 pl-4">
                    <li>
                        É uma assinatura no perfil PAdES-B-B, acrescentada ao
                        arquivo depois do relatório de evidências, sem apagar as
                        assinaturas anteriores. Ela não substitui o aceite
                        eletrônico e não é a assinatura da operadora.
                    </li>
                    {state.notices.map((notice) => (
                        <li key={notice}>{notice}</li>
                    ))}
                    <li>{state.chain.label}</li>
                    <li>{state.chain.revocation_label}</li>
                    <li>
                        Uma assinatura feita pelo simulador é sempre marcada
                        como “simulado — nenhum token foi usado” e não tem valor
                        para uso real.
                    </li>
                </ul>
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
            icon={Usb}
            label="Assinatura com certificado em token ou cartão"
            title="Assinar também com certificado em token (A3)"
            subtitle="Opcional · token ou cartão, com componente instalado no computador"
            badge={badge}
            className={className}
        >
            {children}
        </CardShell>
    );
}

function SectionTitle({ children }: { children: ReactNode }) {
    return (
        <p className="text-muted-foreground text-[11px] font-semibold tracking-[.08em] uppercase">
            {children}
        </p>
    );
}

function ChoiceButton({
    selected,
    disabled = false,
    onClick,
    title,
    description,
}: {
    selected: boolean;
    disabled?: boolean;
    onClick: () => void;
    title: ReactNode;
    description: string;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'focus-ring flex min-h-11 flex-col items-start gap-0.5 rounded-[10px] border px-3 py-2.5 text-left transition-colors',
                selected
                    ? 'border-primary bg-accent-subtle'
                    : 'border-border hover:border-primary hover:bg-accent-subtle',
                disabled &&
                    'hover:border-border cursor-not-allowed opacity-60 hover:bg-transparent',
            )}
        >
            <span className="flex items-center text-[13px] font-semibold">
                {title}
            </span>
            <span className="text-muted-foreground text-[12px] leading-[1.45]">
                {description}
            </span>
        </button>
    );
}

function DocumentRow({
    doc,
    multi,
    pending,
    canPrepare,
    choice,
    busy,
    onPrepare,
    onSign,
}: {
    doc: ExternalSigningDocument;
    multi: boolean;
    pending: ExternalPending | null;
    canPrepare: boolean;
    choice: Choice | null;
    busy: string | null;
    onPrepare: () => void;
    onSign: () => void;
}) {
    const name = doc.name?.trim() || `Arquivo ${doc.position}`;
    const expired =
        pending !== null && new Date(pending.expires_at).getTime() < Date.now();
    // Componente real precisa do resumo em memória (só vem na resposta de `prepare`).
    const signable =
        pending !== null &&
        !expired &&
        (pending.simulated
            ? choice === 'simulated'
            : choice === 'nexu' && pending.digest !== null);

    return (
        <li className="border-border flex flex-col gap-2.5 rounded-[10px] border p-3.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="min-w-0 text-[13px] font-semibold">
                    {multi ? `${doc.position}. ` : ''}
                    {name}
                </span>
                <Badge variant={externalDocumentStatusTones[doc.status]}>
                    {externalDocumentStatusLabels[doc.status]}
                </Badge>
            </div>

            {doc.status === 'signed' && doc.signed_at && (
                <p className="text-text-secondary text-[12.5px]">
                    Assinado em {formatDateTime(doc.signed_at)}.
                </p>
            )}

            {doc.status === 'busy' && (
                <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                    Outro participante está assinando este arquivo agora
                    {doc.retry_after
                        ? `; tente de novo depois de ${formatTime(doc.retry_after)}`
                        : ''}
                    .
                </p>
            )}

            {doc.status === 'waiting_base' && (
                <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                    O arquivo consolidado ainda está sendo preparado. Volte em
                    alguns instantes.
                </p>
            )}

            {(doc.status === 'to_sign' || doc.status === 'reserved') && (
                <>
                    {pending && (
                        <div className="bg-sidebar flex flex-col gap-2.5 rounded-md p-3">
                            <p className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-[11px] font-semibold tracking-[.08em] uppercase">
                                Certificado lido pelo servidor
                                {pending.simulated && <SimulatedTag />}
                            </p>
                            {pending.certificate.is_test ? (
                                <TestCertificateNotice
                                    label={pending.certificate.kind_label}
                                />
                            ) : (
                                <p className="text-muted-foreground text-[12px] leading-[1.5]">
                                    {pending.certificate.kind_label}
                                </p>
                            )}
                            <CertificateFacts
                                certificate={pending.certificate}
                                showSerial={false}
                            />
                            <p className="text-text-secondary text-[12px] leading-[1.5]">
                                {pending.chain.label}{' '}
                                {pending.chain.revocation_label}
                            </p>
                            <p
                                className={cn(
                                    'flex items-center gap-1.5 text-[12px]',
                                    expired
                                        ? 'text-warning'
                                        : 'text-text-secondary',
                                )}
                            >
                                <Clock className="size-3.5 shrink-0" />
                                {expired
                                    ? 'O resumo preparado venceu. Prepare de novo.'
                                    : `Resumo válido até ${formatTime(pending.expires_at)}.`}
                            </p>
                            {!pending.simulated &&
                                pending.digest === null &&
                                !expired && (
                                    <p className="text-text-secondary text-[12px] leading-[1.5]">
                                        Esta preparação foi feita antes de a
                                        página ser recarregada. Prepare de novo
                                        para assinar com o token.
                                    </p>
                                )}
                        </div>
                    )}

                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        {signable && (
                            <Button
                                type="button"
                                className="w-full sm:w-auto"
                                onClick={onSign}
                                disabled={busy !== null}
                            >
                                {busy === `sign:${doc.id}` ? (
                                    <Spinner className="size-4" />
                                ) : (
                                    <KeyRound className="size-[15px]" />
                                )}
                                {pending?.simulated
                                    ? 'Assinar com o simulador'
                                    : 'Assinar com o token'}
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant={signable ? 'outline' : 'default'}
                            className="w-full sm:w-auto"
                            onClick={onPrepare}
                            disabled={!canPrepare || busy !== null}
                        >
                            {busy === `prepare:${doc.id}` && (
                                <Spinner className="size-4" />
                            )}
                            {pending ? 'Preparar de novo' : 'Preparar resumo'}
                        </Button>
                    </div>
                    {busy === `sign:${doc.id}` && !pending?.simulated && (
                        <p className="text-text-secondary text-[12px] leading-[1.5]">
                            Confirme no componente: escolha o certificado e
                            digite o PIN do token na janela dele. Se a janela
                            não aparecer, ela pode ter aberto atrás do
                            navegador.
                        </p>
                    )}
                </>
            )}
        </li>
    );
}

export function WithdrawControl({
    open,
    onOpenChange,
    onConfirm,
    busy,
    disabled,
    question,
    label,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    busy: boolean;
    disabled: boolean;
    question: string;
    label: string;
}) {
    return open ? (
        <div className="border-border flex flex-col gap-2 rounded-[10px] border p-3 text-[12.5px]">
            <p>{question}</p>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="destructive"
                    size="xs"
                    onClick={onConfirm}
                    disabled={disabled}
                >
                    {busy && <Spinner className="size-3.5" />}
                    Confirmar desistência
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="xs"
                    onClick={() => onOpenChange(false)}
                >
                    Voltar
                </Button>
            </div>
        </div>
    ) : (
        <button
            type="button"
            onClick={() => onOpenChange(true)}
            className="text-muted-foreground hover:text-danger self-start text-[12.5px] font-semibold"
        >
            {label}
        </button>
    );
}
