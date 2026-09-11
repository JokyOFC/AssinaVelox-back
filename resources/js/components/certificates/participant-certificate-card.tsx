import { router } from '@inertiajs/react';
import {
    BadgeCheck,
    CircleAlert,
    Clock,
    FileKey2,
    RefreshCw,
    Upload,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useRef,
    useState,
    type KeyboardEvent,
    type ReactNode,
} from 'react';
import { Emphasis, LegalText } from '@/components/sign/legal-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime, plural } from '@/lib/format';
import {
    participantCertificateErrorTitles,
    participantRequestTone,
} from '@/lib/labels';
import { cn } from '@/lib/utils';
import { show as certificateShow } from '@/routes/sign/certificate';
import { send as otpSend } from '@/routes/sign/otp';
import type {
    ParticipantCertificateError,
    ParticipantCertificatePreview,
    ParticipantCertificateState,
} from '@/types/signatures';
import { CertificateFacts, TestCertificateNotice } from './certificate-facts';
import { certificateRequest, type CertificateResponse } from './http';

/** Consulta do estado enquanto a assinatura é aplicada (limites: `show` 60/min). */
const POLL_MS = 4000;
const MAX_POLLS = 150;

type Busy = 'intent' | 'withdraw' | 'inspect' | 'store' | 'refresh' | null;
type FieldKey = 'certificate' | 'password' | 'consent';
type Alert = { title: string; message: string } | null;

/**
 * Assinar TAMBÉM com o certificado A1 do próprio participante (Fase 2 §2.12 —
 * docs/fase-2/a1-do-participante.md §7), na página pública.
 *
 * - O recurso é descoberto por `GET sign.certificate.show`: 404 (flag desligada, papel sem
 *   assinatura, envelope encerrado) = o cartão não aparece e a página é a de antes.
 * - É uma opção SEPARADA da representação visual e do aceite: o aceite eletrônico continua
 *   obrigatório e valendo; esta assinatura só se soma a ele (T1).
 * - O certificado é lido pelo SERVIDOR (prévia) antes de qualquer autorização; o
 *   consentimento é específico, com o texto integral do servidor e a caixa desmarcada.
 * - Segredos: a senha fica só na memória deste componente, num campo sem autocompletar e
 *   fora de `<form>` (o navegador não oferece guardá-la), e é apagada depois do envio e a
 *   cada troca de arquivo. O arquivo e a senha viajam só nos dois POSTs multipart.
 */
export function ParticipantCertificateCard({
    token,
    className,
    onApplied,
}: {
    token: string;
    className?: string;
    /** Chamado quando a assinatura sai de "em aplicação" (para recarregar o comprovante). */
    onApplied?: () => void;
}) {
    const showUrl = certificateShow(token).url;
    const ids = useId();

    const [state, setState] = useState<ParticipantCertificateState | null>(
        null,
    );
    const [probe, setProbe] = useState<'loading' | 'ready' | 'hidden'>(
        'loading',
    );
    const [loadError, setLoadError] = useState(false);
    const [busy, setBusy] = useState<Busy>(null);
    const [alert, setAlert] = useState<Alert>(null);
    const [invalid, setInvalid] = useState<Partial<Record<FieldKey, true>>>({});
    const [expanded, setExpanded] = useState(false);
    const [confirmWithdraw, setConfirmWithdraw] = useState(false);

    // Material sensível: só na memória, apagado depois do uso.
    const [file, setFile] = useState<File | null>(null);
    const [password, setPassword] = useState('');
    const [preview, setPreview] =
        useState<ParticipantCertificatePreview | null>(null);
    const [consented, setConsented] = useState(false);

    const fileInput = useRef<HTMLInputElement | null>(null);
    const polls = useRef(0);
    const previousStage = useRef<string | null>(null);

    const clearSecrets = useCallback(() => {
        setPassword('');
        setFile(null);
        setPreview(null);
        setConsented(false);

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    }, []);

    const refresh = useCallback(
        async (quiet = false) => {
            if (!quiet) {
                setBusy('refresh');
            }

            const response =
                await certificateRequest<ParticipantCertificateState>(
                    'GET',
                    showUrl,
                );

            setBusy((value) => (value === 'refresh' ? null : value));

            if (response.status === 404) {
                // Recurso desligado / não oferecido a esta pessoa: a página segue a de antes.
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

    // Acompanha a aplicação (fila) até sair de "recebido"/"aplicando".
    const stage = state?.stage ?? null;

    useEffect(() => {
        const before = previousStage.current;
        previousStage.current = stage;

        if (
            (before === 'queued' || before === 'applying') &&
            stage !== 'queued' &&
            stage !== 'applying'
        ) {
            onApplied?.();
        }

        if (stage !== 'queued' && stage !== 'applying') {
            polls.current = 0;

            return;
        }

        if (polls.current >= MAX_POLLS) {
            return;
        }

        const timer = window.setTimeout(() => {
            polls.current += 1;
            void refresh(true);
        }, POLL_MS);

        return () => window.clearTimeout(timer);
    }, [stage, state, refresh, onApplied]);

    if (probe !== 'ready') {
        return null;
    }

    if (!state) {
        return loadError ? (
            <Shell className={className}>
                <p className="text-text-secondary text-[12.5px]">
                    Não foi possível carregar a opção de assinar com o seu
                    certificado.
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

    const request = state.request;

    if (state.stage === 'closed' && request === null) {
        return null;
    }

    // --- Erros do servidor ---------------------------------------------------

    const handleFailure = (
        response: CertificateResponse<unknown>,
        fallback: string,
    ) => {
        if (response.status === 429) {
            setAlert({
                title: 'Muitas tentativas seguidas',
                message: `Aguarde ${response.retryAfter ?? 60} segundos e tente de novo.`,
            });

            return;
        }

        const body = response.body as ParticipantCertificateError | null;

        if (response.network || !body || typeof body.message !== 'string') {
            setAlert({ title: 'Sem resposta do servidor', message: fallback });

            return;
        }

        const code = body.code ?? '';
        const marked: Partial<Record<FieldKey, true>> = {};

        (['certificate', 'password', 'consent'] as FieldKey[]).forEach(
            (key) => {
                if (body.errors?.[key]?.length) {
                    marked[key] = true;
                }
            },
        );

        setInvalid(marked);
        setAlert({
            title:
                participantCertificateErrorTitles[code] ??
                (response.status === 422
                    ? 'Confira os dados informados'
                    : 'Não foi possível concluir'),
            message: body.message,
        });

        if (code === 'wrong_passphrase') {
            setPassword('');
        }

        if (code === 'certificate_changed') {
            setPreview(null);
            setConsented(false);
        }

        if (
            [
                'not_ready',
                'already_queued',
                'already_applied',
                'window_closed',
                'envelope_closed',
                'not_authenticated',
                'cannot_withdraw',
            ].includes(code)
        ) {
            void refresh(true);
        }
    };

    const resetMessages = () => {
        setAlert(null);
        setInvalid({});
    };

    // --- Ações ---------------------------------------------------------------

    const requestIntent = async () => {
        resetMessages();
        setBusy('intent');
        const response = await certificateRequest<ParticipantCertificateState>(
            'POST',
            state.endpoints.intent,
        );
        setBusy(null);

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
        resetMessages();
        setBusy('withdraw');
        const response = await certificateRequest<ParticipantCertificateState>(
            'POST',
            state.endpoints.withdraw,
        );
        setBusy(null);
        setConfirmWithdraw(false);

        if (response.ok && response.body?.available) {
            setState(response.body);
            setExpanded(false);
            clearSecrets();

            return;
        }

        handleFailure(
            response,
            'Não conseguimos registrar a desistência. Tente de novo.',
        );
    };

    const localCheck = (): boolean => {
        const max = state.limits.max_upload_kb * 1024;
        const extensions = state.limits.accepted_extensions.map((item) =>
            item.toLowerCase(),
        );

        if (!file) {
            setInvalid({ certificate: true });
            setAlert({
                title: 'Escolha o arquivo do certificado',
                message: `Selecione o arquivo ${extensions.map((item) => `.${item}`).join(' ou ')} do seu certificado A1.`,
            });

            return false;
        }

        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (!extensions.includes(extension)) {
            setInvalid({ certificate: true });
            setAlert({
                title: 'Tipo de arquivo não aceito',
                message: `O certificado A1 é um arquivo ${extensions.map((item) => `.${item}`).join(' ou ')}. Certificados em cartão, token ou nuvem (A3) não são aceitos aqui.`,
            });

            return false;
        }

        if (file.size === 0 || file.size > max) {
            setInvalid({ certificate: true });
            setAlert({
                title:
                    file.size === 0
                        ? 'Arquivo vazio'
                        : participantCertificateErrorTitles.pkcs12_too_large,
                message:
                    file.size === 0
                        ? 'O arquivo escolhido está vazio. Escolha o arquivo do certificado de novo.'
                        : `O limite é ${formatBytes(max)}. Um certificado A1 costuma ter poucos KB — confira se escolheu o arquivo certo.`,
            });

            return false;
        }

        if (password === '') {
            setInvalid({ password: true });
            setAlert({
                title: 'Informe a senha do certificado',
                message:
                    'É a senha definida quando o certificado foi emitido ou exportado.',
            });

            return false;
        }

        return true;
    };

    const inspect = async () => {
        resetMessages();

        if (!localCheck() || !file) {
            return;
        }

        const body = new FormData();
        body.append('certificate', file);
        body.append('password', password);

        setBusy('inspect');
        const response =
            await certificateRequest<ParticipantCertificatePreview>(
                'POST',
                state.endpoints.inspect,
                body,
                { timeoutMs: 60000 },
            );
        setBusy(null);

        if (response.ok && response.body?.certificate) {
            setPreview(response.body);
            setConsented(false);

            return;
        }

        handleFailure(
            response,
            'Não recebemos o resultado da conferência. Confira a conexão e tente de novo.',
        );
    };

    const submit = async () => {
        resetMessages();

        if (!preview || !file || !consented) {
            if (!consented) {
                setInvalid({ consent: true });
            }

            return;
        }

        const body = new FormData();
        body.append('certificate', file);
        body.append('password', password);
        body.append('consent', '1');
        body.append('consent_version', preview.consent.version);
        body.append('fingerprint', preview.certificate.fingerprint_sha256);

        setBusy('store');
        const response = await certificateRequest<ParticipantCertificateState>(
            'POST',
            state.endpoints.store,
            body,
            { timeoutMs: 120000 },
        );
        setBusy(null);

        if (response.ok && response.body?.available) {
            clearSecrets();
            setExpanded(false);
            setState(response.body);

            return;
        }

        if (response.network) {
            // Sem confirmação não se afirma nada (T5): apaga a senha e consulta o estado real.
            clearSecrets();
            setAlert({
                title: 'Não recebemos a confirmação do envio',
                message:
                    'Confira abaixo o estado atualizado antes de enviar de novo.',
            });
            void refresh(true);

            return;
        }

        handleFailure(response, 'Não foi possível enviar o certificado.');
    };

    const onPasswordKey = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            event.preventDefault();

            if (preview === null && busy === null) {
                void inspect();
            }
        }
    };

    // --- Partes da tela ------------------------------------------------------

    const stageBadge = (() => {
        if (request === null) {
            return null;
        }

        if (state.stage === 'awaiting_others') {
            return <Badge variant="info">Escolha registrada</Badge>;
        }

        if (state.stage === 'ready_to_upload') {
            return <Badge variant="warning">Aguardando seu certificado</Badge>;
        }

        return (
            <Badge variant={participantRequestTone(request.status)}>
                {request.status_label}
            </Badge>
        );
    })();

    // Quem já aceitou e volta depois da janela de download confirma a identidade de
    // novo com um código (o servidor aceita o pedido enquanto o envio do certificado
    // estiver aberto para esta pessoa). O formulário do código aparece na própria página.
    const requestCode = () => {
        router.post(
            otpSend(token).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    document
                        .getElementById('certificate-reauth')
                        ?.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center',
                        }),
            },
        );
    };

    const notAuthenticated = !state.authenticated && (
        <Note tone="warning">
            Para continuar, confirme sua identidade de novo: enviaremos um
            código para você informar aqui mesmo nesta página.
            {state.message && (
                <span className="mt-1 block">{state.message}</span>
            )}
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={requestCode}
            >
                Receber código
            </Button>
        </Note>
    );

    const withdrawControl = state.can_withdraw && (
        <div className="flex flex-col gap-2">
            {confirmWithdraw ? (
                <div className="border-border flex flex-col gap-2 rounded-[10px] border p-3 text-[12.5px]">
                    <p>
                        Desistir de assinar com o certificado? O seu aceite
                        eletrônico continua valendo.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="destructive"
                            size="xs"
                            onClick={() => void withdraw()}
                            disabled={busy !== null}
                        >
                            {busy === 'withdraw' && (
                                <Spinner className="size-3.5" />
                            )}
                            Confirmar desistência
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="xs"
                            onClick={() => setConfirmWithdraw(false)}
                        >
                            Voltar
                        </Button>
                    </div>
                </div>
            ) : (
                <button
                    type="button"
                    onClick={() => setConfirmWithdraw(true)}
                    className="text-muted-foreground hover:text-danger self-start text-[12.5px] font-semibold"
                >
                    Desistir de assinar com o certificado
                </button>
            )}
        </div>
    );

    const uploader = (
        <div className="border-border flex flex-col gap-3 border-t pt-3.5">
            {request?.window_expires_at && (
                <p className="text-text-secondary flex items-start gap-1.5 text-[12.5px]">
                    <Clock className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        Prazo para enviar o certificado:{' '}
                        <b className="tabular">
                            {formatDateTime(request.window_expires_at)}
                        </b>
                        .
                    </span>
                </p>
            )}

            <div className="grid gap-1.5">
                <Label htmlFor={`${ids}-file`}>Arquivo do certificado</Label>
                <input
                    ref={fileInput}
                    id={`${ids}-file`}
                    type="file"
                    accept={state.limits.accepted_extensions
                        .map((item) => `.${item}`)
                        .concat('application/x-pkcs12')
                        .join(',')}
                    className="peer sr-only"
                    aria-invalid={invalid.certificate === true}
                    onChange={(event) => {
                        setFile(event.target.files?.[0] ?? null);
                        // Trocou o arquivo: a prévia e a senha anteriores não valem mais.
                        setPreview(null);
                        setConsented(false);
                        setPassword('');
                        resetMessages();
                    }}
                />
                <label
                    htmlFor={`${ids}-file`}
                    className={cn(
                        // O input (sr-only) é IRMÃO do label: o foco do teclado aparece via `peer`.
                        'border-border-dashed text-text-secondary hover:border-primary hover:bg-accent-subtle peer-focus-visible:border-primary peer-focus-visible:ring-ring/50 flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] border border-dashed px-3 py-2.5 text-[13px] peer-focus-visible:ring-[3px]',
                        invalid.certificate && 'border-danger',
                    )}
                >
                    <Upload className="size-4 shrink-0" />
                    <span className="min-w-0 truncate">
                        {file
                            ? `${file.name} · ${formatBytes(file.size)}`
                            : `Escolher arquivo ${state.limits.accepted_extensions.map((item) => `.${item}`).join(' ou ')}`}
                    </span>
                </label>
                <p className="text-muted-foreground text-[11.5px]">
                    Certificado A1 em arquivo, até{' '}
                    {formatBytes(state.limits.max_upload_kb * 1024)}.
                </p>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor={`${ids}-pass`}>Senha do certificado</Label>
                <Input
                    id={`${ids}-pass`}
                    type="password"
                    name="av-certificate-passphrase"
                    autoComplete="off"
                    autoCorrect="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    data-1p-ignore="true"
                    data-lpignore="true"
                    data-bwignore="true"
                    data-form-type="other"
                    value={password}
                    aria-invalid={invalid.password === true}
                    onChange={(event) => {
                        setPassword(event.target.value);

                        if (preview) {
                            setPreview(null);
                            setConsented(false);
                        }
                    }}
                    onKeyDown={onPasswordKey}
                />
                <p className="text-muted-foreground text-[11.5px] leading-[1.45]">
                    A senha abre o certificado na conferência e na aplicação da
                    assinatura. Ela não é guardada pela plataforma nem neste
                    navegador.
                </p>
            </div>

            {preview === null && (
                <Button
                    type="button"
                    variant="outline"
                    className="w-full sm:w-auto sm:self-start"
                    onClick={() => void inspect()}
                    disabled={busy !== null || !file || password === ''}
                >
                    {busy === 'inspect' ? (
                        <Spinner className="size-4" />
                    ) : (
                        <FileKey2 className="size-[15px]" />
                    )}
                    Conferir certificado
                </Button>
            )}

            {preview && (
                <div className="flex flex-col gap-3">
                    <div className="border-border flex flex-col gap-2.5 rounded-[10px] border p-3.5">
                        <p className="text-muted-foreground text-[11px] font-semibold tracking-[.08em] uppercase">
                            Certificado lido pelo servidor
                        </p>
                        <p className="text-[13px] leading-[1.45] font-semibold">
                            {preview.certificate.label}
                        </p>
                        {preview.certificate.is_test ? (
                            <TestCertificateNotice
                                label={preview.certificate.kind_label}
                            />
                        ) : (
                            <p className="text-muted-foreground text-[12px] leading-[1.5]">
                                {preview.certificate.kind_label}
                            </p>
                        )}
                        <CertificateFacts certificate={preview.certificate} />
                        {preview.holder_match.name === 'different' && (
                            <Note tone="warning">
                                O nome do titular do certificado é diferente do
                                nome informado neste documento. Confira se o
                                certificado é mesmo seu antes de autorizar.
                            </Note>
                        )}
                        {preview.certificate.warnings.length > 0 && (
                            <ul className="text-warning flex flex-col gap-1 text-[12px] leading-[1.45]">
                                {preview.certificate.warnings.map((warning) => (
                                    <li
                                        key={warning}
                                        className="flex items-start gap-1.5"
                                    >
                                        <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                                        <span>{warning}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-muted-foreground text-[11.5px] leading-[1.45]">
                            {preview.holder_match.rule}
                        </p>
                    </div>

                    <div className="flex flex-col gap-2.5">
                        <div className="flex items-start gap-2.5">
                            <Checkbox
                                id={`${ids}-consent`}
                                checked={consented}
                                aria-invalid={invalid.consent === true}
                                onCheckedChange={(value) =>
                                    setConsented(value === true)
                                }
                                className="mt-0.5"
                            />
                            <label
                                htmlFor={`${ids}-consent`}
                                className="text-text-secondary cursor-pointer text-[12.5px] leading-[1.5]"
                            >
                                <Emphasis
                                    text={preview.consent.checkbox_label}
                                />
                            </label>
                        </div>
                        {preview.consent.statement.trim() !== '' && (
                            <div className="border-border bg-sidebar max-h-[240px] overflow-y-auto rounded-[10px] border p-3">
                                <LegalText text={preview.consent.statement} />
                            </div>
                        )}
                        <p className="text-muted-foreground text-[11.5px]">
                            Versão do texto de autorização:{' '}
                            <span className="tabular">
                                {preview.consent.version}
                            </span>
                        </p>
                    </div>

                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        <Button
                            type="button"
                            size="lg"
                            className="w-full sm:w-auto"
                            onClick={() => void submit()}
                            disabled={!consented || busy !== null}
                        >
                            {busy === 'store' ? (
                                <Spinner className="size-4" />
                            ) : (
                                <BadgeCheck className="size-4" />
                            )}
                            Autorizar e assinar com este certificado
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="lg"
                            className="w-full sm:w-auto"
                            onClick={() => {
                                clearSecrets();
                                resetMessages();
                            }}
                            disabled={busy !== null}
                        >
                            Trocar certificado
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );

    // --- Corpo por estágio -----------------------------------------------------

    let body: ReactNode = null;

    switch (state.stage) {
        case 'choose':
            body = (
                <>
                    <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                        O certificado é enviado depois que todos os
                        participantes concluírem o aceite, porque a assinatura
                        cobre o arquivo já consolidado. Agora só a sua escolha
                        fica registrada — nenhum arquivo e nenhuma senha.
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
                                <FileKey2 className="size-[15px]" />
                            )}
                            Quero assinar também com meu certificado
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
                            'Volte por este link quando todos os participantes tiverem concluído o aceite para enviar o certificado.'}
                    </Note>
                    {notAuthenticated}
                    {withdrawControl}
                </>
            );
            break;

        case 'ready_to_upload':
            body =
                request !== null || expanded ? (
                    <>
                        <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                            Todos concluíram o aceite e o arquivo está pronto.
                            Envie o certificado para acrescentar a sua
                            assinatura; o servidor mostra os dados lidos antes
                            de você autorizar.
                        </p>
                        {state.can_upload ? uploader : notAuthenticated}
                        {withdrawControl}
                    </>
                ) : (
                    <>
                        <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                            O arquivo está pronto. Se quiser, acrescente uma
                            assinatura com o seu certificado A1.
                        </p>
                        {state.can_upload ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full sm:w-auto sm:self-start"
                                onClick={() => setExpanded(true)}
                            >
                                <FileKey2 className="size-[15px]" />
                                Assinar também com meu certificado
                            </Button>
                        ) : (
                            notAuthenticated
                        )}
                    </>
                );
            break;

        case 'failed':
            body = (
                <>
                    <Note tone="danger">
                        <b>A assinatura com o certificado não foi aplicada.</b>{' '}
                        {request?.failure?.message ??
                            'Nada foi gravado no arquivo. Você pode enviar o certificado de novo dentro do prazo.'}
                    </Note>
                    {state.can_upload ? uploader : notAuthenticated}
                    {withdrawControl}
                </>
            );
            break;

        case 'queued':
        case 'applying':
            body = (
                <>
                    <Note tone="info" icon={<Spinner className="size-4" />}>
                        <b>
                            {state.stage === 'queued'
                                ? 'Recebemos o seu certificado.'
                                : 'Aplicando a assinatura…'}
                        </b>{' '}
                        A assinatura é aplicada em seguida, uma de cada vez,
                        para preservar as anteriores. O arquivo e a senha ficam
                        cifrados por no máximo{' '}
                        {plural(state.limits.sealed_ttl_minutes, 'minuto')} e
                        são apagados logo depois do uso.
                    </Note>
                    {request?.certificate && (
                        <p className="text-text-secondary text-[12.5px]">
                            {request.certificate.label}
                        </p>
                    )}
                    {polls.current >= MAX_POLLS && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="self-start"
                            onClick={() => {
                                polls.current = 0;
                                void refresh();
                            }}
                            disabled={busy !== null}
                        >
                            <RefreshCw className="size-[15px]" />
                            Atualizar
                        </Button>
                    )}
                    {withdrawControl}
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
                            {request?.certificate?.label ??
                                'Assinatura com o seu certificado aplicada.'}
                        </b>
                        {request?.applied_at && (
                            <span className="mt-1 block">
                                Aplicada em {formatDateTime(request.applied_at)}
                                {request.documents_signed > 0 &&
                                    ` · ${plural(request.documents_signed, 'arquivo assinado', 'arquivos assinados')}`}
                                .
                            </span>
                        )}
                        <span className="mt-1 block">
                            O certificado e a senha já foram descartados. A
                            assinatura aparece no arquivo final e na página de
                            verificação, ao lado do seu aceite eletrônico.
                        </span>
                    </Note>
                    {request?.certificate?.is_test && (
                        <TestCertificateNotice
                            label={request.certificate.kind_label}
                        />
                    )}
                </>
            );
            break;

        case 'expired':
            body = (
                <Note tone="neutral">
                    O prazo para enviar o certificado terminou
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
                        Você desistiu de assinar com o certificado. O seu aceite
                        eletrônico continua valendo.
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
                            Quero assinar com meu certificado
                        </Button>
                    )}
                </>
            );
            break;

        default:
            body = (
                <Note tone="neutral">
                    {state.message ??
                        'Este documento não está mais recebendo assinaturas com certificado.'}
                </Note>
            );
    }

    return (
        <Shell className={className} badge={stageBadge}>
            <p className="text-text-secondary text-[12.5px] leading-[1.55]">
                Além do seu aceite eletrônico, você pode acrescentar ao arquivo
                final uma{' '}
                <b className="text-foreground">
                    assinatura criptográfica feita com o seu próprio certificado
                </b>
                , que identifica você pelo titular do certificado. Ela não
                substitui o aceite eletrônico, que continua valendo, e não é a
                assinatura da operadora.
            </p>

            <details className="text-[12.5px] leading-[1.5]">
                <summary className="text-primary cursor-pointer font-semibold">
                    O que é e o que não é
                </summary>
                <ul className="text-text-secondary mt-2 flex list-disc flex-col gap-1 pl-4">
                    <li>
                        É uma assinatura no perfil PAdES-B-B, acrescentada ao
                        arquivo depois do relatório de evidências, sem apagar as
                        assinaturas anteriores.
                    </li>
                    {state.notices.map((notice) => (
                        <li key={notice}>{notice}</li>
                    ))}
                    <li>
                        Se o certificado não for enviado no prazo (
                        {plural(
                            Math.round(
                                state.limits.application_window_minutes / 60,
                            ),
                            'hora',
                        )}{' '}
                        depois que todos concluírem o aceite), o documento é
                        concluído sem esta assinatura.
                    </li>
                </ul>
            </details>

            {body}

            {alert && (
                <div
                    role="alert"
                    className="border-danger-border bg-danger-bg text-danger flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]"
                >
                    <CircleAlert className="mt-0.5 size-4 shrink-0" />
                    <span>
                        <b>{alert.title}.</b> {alert.message}
                    </span>
                </div>
            )}
        </Shell>
    );
}

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
        <section
            aria-label="Assinatura com o seu certificado digital"
            className={cn(
                'border-border bg-card shadow-card flex flex-col gap-3.5 rounded-[14px] border p-5 sm:p-[22px]',
                className,
            )}
        >
            <div className="flex items-start gap-3">
                <span className="bg-primary-soft text-primary flex size-9 shrink-0 items-center justify-center rounded-[10px]">
                    <FileKey2 className="size-[18px]" />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-[15px] leading-tight font-semibold">
                        Assinar também com o seu certificado digital
                    </h2>
                    <p className="text-muted-foreground mt-0.5 text-[12.5px]">
                        Opcional · certificado A1 (arquivo .pfx ou .p12)
                    </p>
                </div>
                {badge}
            </div>
            {children}
        </section>
    );
}

function Note({
    tone,
    icon,
    children,
}: {
    tone: 'info' | 'success' | 'warning' | 'danger' | 'neutral';
    icon?: ReactNode;
    children: ReactNode;
}) {
    const classes = {
        info: 'border-primary-soft-border bg-primary-soft text-primary',
        success: 'border-success-border bg-success-bg text-success',
        warning: 'border-warning-border bg-warning-bg text-warning',
        danger: 'border-danger-border bg-danger-bg text-danger',
        neutral: 'border-neutral-border bg-neutral-bg text-text-secondary',
    }[tone];

    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]',
                classes,
            )}
        >
            {icon && <span className="mt-0.5 shrink-0">{icon}</span>}
            <div className="min-w-0">{children}</div>
        </div>
    );
}
