import { CircleAlert, ShieldCheck, ShieldOff } from 'lucide-react';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SignatureStatus } from '@/types/enums';

/**
 * Certificado da operadora como o backend o expõe. Os dois nomes de campo
 * convivem de propósito: a página pública manda `subject_cn`/`issuer_cn`
 * (ROUTES §2.19) e a de evidências manda `subject`/`issuer` (§2.8).
 */
export interface VerificationCertificate {
    subject?: string | null;
    subject_cn?: string | null;
    issuer?: string | null;
    issuer_cn?: string | null;
    serial?: string | null;
    valid_from?: string | null;
    valid_to?: string | null;
    /** Perfil PAdES aplicado. Na Fase 1 é sempre `PAdES-B-B`. */
    policy?: string | null;
    /** `test` obriga o aviso de que o arquivo não serve para uso real. */
    environment?: 'test' | 'production' | null;
    environment_label?: string | null;
    is_test?: boolean | null;
}

/**
 * Resultado técnico da validação (`SignatureNarrative::validation`). Cada rótulo já vem
 * escrito pelo servidor em linguagem honesta: integridade só é afirmada quando a
 * ferramenta confirmou, e revogação é sempre declarada não verificada na Fase 1.
 */
export interface VerificationValidation {
    available: boolean;
    summary: string | null;
    validated_at: string | null;
    signature_count: number;
    integrity: 'intact' | 'broken' | 'unknown';
    integrity_label: string;
    chain_trust: 'trusted' | 'not_verified';
    chain_trust_label: string;
    revocation: string;
    revocation_label: string;
    notes: string[];
}

/**
 * Nome comum do titular. O CN é o que a ROUTES §4.2 manda exibir; o DN inteiro
 * ("CN=…, O=…, C=BR") só entra quando o backend não separou o CN.
 */
export function certificateSubject(
    certificate: VerificationCertificate | null | undefined,
): string | null {
    return certificate?.subject_cn ?? certificate?.subject ?? null;
}

export function certificateIssuer(
    certificate: VerificationCertificate | null | undefined,
): string | null {
    return certificate?.issuer_cn ?? certificate?.issuer ?? null;
}

/**
 * Aviso obrigatório quando o certificado é de teste (declaração §5.2). Nunca
 * apresentar certificado de teste como ICP-Brasil.
 */
export function TestCertificateWarning({
    environment,
    className,
}: {
    environment: string | null | undefined;
    className?: string;
}) {
    if (environment !== 'test') {
        return null;
    }

    return (
        <p
            className={cn(
                'border-warning-border bg-warning-bg text-warning flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]',
                className,
            )}
        >
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>
                <b>Certificado de ambiente de teste.</b> A assinatura deste
                arquivo foi aplicada com um certificado de teste, sem valor para
                uso em produção e sem qualquer relação com a ICP-Brasil. Este
                arquivo não deve ser utilizado para fins reais.
            </span>
        </p>
    );
}

/**
 * Bloco "Sobre a assinatura criptográfica deste arquivo"
 * (declaracao-de-aceite.md §5.2 e §5.3), com o texto que corresponde ao
 * `signature_status` real da finalização.
 */
export function SignatureStatement({
    status,
    certificate,
    verifyLabel,
    verificationCode,
    validationSummary,
    statement,
    className,
}: {
    status: SignatureStatus;
    certificate?: VerificationCertificate | null;
    /** Ex.: "assinavelox.com.br/verificar". */
    verifyLabel?: string | null;
    /** Já formatado em blocos (XXXX-XXXX-XXXX). */
    verificationCode?: string | null;
    /** Resultado técnico da validação no momento da conclusão. */
    validationSummary?: string | null;
    /**
     * Texto autoral do servidor (`SignatureNarrative::statement`). Quando presente,
     * ele vence: é a redação revisada de docs/juridico/declaracao-de-aceite.md §5.2/§5.3,
     * e duplicá-la aqui seria criar uma segunda fonte de verdade jurídica.
     */
    statement?: string | null;
    className?: string;
}) {
    const signed = status === 'company_a1';
    const subject = certificateSubject(certificate);
    const issuer = certificateIssuer(certificate);
    const policy = certificate?.policy ?? 'PAdES-B-B';

    return (
        <div className={cn('flex flex-col gap-2.5', className)}>
            <div
                className={cn(
                    'flex items-start gap-3 rounded-[10px] border p-3.5 text-[12.5px] leading-[1.55]',
                    signed
                        ? 'border-success-border bg-success-bg text-success'
                        : 'border-neutral-border bg-neutral-bg text-text-secondary',
                )}
            >
                {signed ? (
                    <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                ) : (
                    <ShieldOff className="mt-0.5 size-4 shrink-0" />
                )}
                <div className="min-w-0">
                    {statement ? (
                        <>
                            <p>{statement}</p>
                            {signed && validationSummary && (
                                <p className="mt-2">
                                    Resultado técnico da validação no momento da
                                    conclusão: {validationSummary}. A
                                    verificação da cadeia de certificação por
                                    terceiros depende das ferramentas e das
                                    políticas de confiança que eles utilizarem.
                                </p>
                            )}
                        </>
                    ) : signed ? (
                        <>
                            <p>
                                Este arquivo recebeu uma assinatura digital no
                                perfil {policy}, aplicada pela{' '}
                                <b>AssinaVelox</b> com certificado digital de{' '}
                                <b>sua própria titularidade</b>
                                {subject ? ` (titular: ${subject}` : ''}
                                {subject && issuer
                                    ? `; emissor: ${issuer}`
                                    : ''}
                                {subject && certificate?.valid_to
                                    ? `; validade até ${formatDate(certificate.valid_to)}`
                                    : ''}
                                {subject ? ')' : ''}. Ela identifica a operadora
                                que consolidou e lacrou o arquivo e permite que
                                leitores de PDF detectem qualquer alteração
                                feita depois do lacre.
                            </p>
                            <p className="mt-2">
                                <b>
                                    Ela não é a assinatura pessoal de nenhum dos
                                    participantes e não é um certificado digital
                                    emitido em nome deles.
                                </b>{' '}
                                A manifestação de vontade de cada participante é
                                o aceite eletrônico, sustentado pelas evidências
                                registradas (data, IP, navegador, autenticação
                                por código enviado ao e-mail, versão do
                                documento e campos preenchidos).
                            </p>
                            {validationSummary && (
                                <p className="mt-2">
                                    Resultado técnico da validação no momento da
                                    conclusão: {validationSummary}. A
                                    verificação da cadeia de certificação por
                                    terceiros depende das ferramentas e das
                                    políticas de confiança que eles utilizarem.
                                </p>
                            )}
                        </>
                    ) : (
                        <>
                            <p>
                                <b>
                                    Este arquivo não possui assinatura
                                    criptográfica.
                                </b>{' '}
                                O documento foi concluído como{' '}
                                <b>aceite eletrônico com evidências</b>: a
                                manifestação de vontade de cada participante
                                está registrada com data, IP, navegador,
                                autenticação por código enviado ao e-mail,
                                versão do documento e campos preenchidos.
                            </p>
                            <p className="mt-2">
                                A integridade do arquivo é conferida comparando
                                o resumo SHA-256 dele com o resumo <b>Final</b>{' '}
                                publicado
                                {verifyLabel ? ` em ${verifyLabel}` : ''}
                                {verificationCode
                                    ? ` sob o código ${verificationCode}`
                                    : ''}
                                . Nenhum certificado digital foi utilizado, e
                                nenhuma indicação de “assinatura digital” deve
                                ser esperada em leitores de PDF.
                            </p>
                        </>
                    )}
                </div>
            </div>
            <TestCertificateWarning environment={certificate?.environment} />
        </div>
    );
}

/** Dados do certificado da operadora, quando houver. */
export function CertificateDetails({
    certificate,
    className,
}: {
    certificate: VerificationCertificate;
    className?: string;
}) {
    const rows: [string, string | null | undefined][] = [
        ['Titular', certificateSubject(certificate)],
        ['Emissor', certificateIssuer(certificate)],
        ['Série', certificate.serial],
        [
            'Validade',
            certificate.valid_from && certificate.valid_to
                ? `${formatDate(certificate.valid_from)} → ${formatDate(certificate.valid_to)}`
                : certificate.valid_to
                  ? `até ${formatDate(certificate.valid_to)}`
                  : null,
        ],
        ['Perfil', certificate.policy],
        [
            'Ambiente',
            certificate.environment === 'test'
                ? 'Teste (sem valor para uso real)'
                : certificate.environment === 'production'
                  ? 'Produção'
                  : null,
        ],
    ];

    return (
        <dl
            className={cn(
                'grid grid-cols-[100px_1fr] gap-x-3 gap-y-1.5 text-[12.5px]',
                className,
            )}
        >
            {rows
                .filter(([, value]) => Boolean(value))
                .map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd
                            className={cn(
                                'break-words',
                                label === 'Série' && 'font-mono break-all',
                            )}
                        >
                            {value}
                        </dd>
                    </div>
                ))}
        </dl>
    );
}

/**
 * Resultado técnico da validação da assinatura, com os rótulos que o servidor escreveu.
 *
 * O que este bloco nunca faz: transformar ausência de informação em sucesso. Quando
 * `available` é falso, o único texto exibido é a razão pela qual não há validação —
 * e não uma frase tranquilizadora.
 */
export function ValidationDetails({
    validation,
    className,
}: {
    validation: VerificationValidation;
    className?: string;
}) {
    const rows: [string, string][] = validation.available
        ? [
              ['Integridade', validation.integrity_label],
              ['Cadeia de certificação', validation.chain_trust_label],
              ['Revogação', validation.revocation_label],
          ]
        : [['Validação', validation.integrity_label]];

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <dl className="grid gap-x-3 gap-y-1.5 text-[12.5px] sm:grid-cols-[150px_1fr]">
                {rows.map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="leading-[1.5]">{value}</dd>
                    </div>
                ))}
                {validation.available && validation.validated_at && (
                    <div className="contents">
                        <dt className="text-muted-foreground">Validado em</dt>
                        <dd className="tabular">
                            {formatDateTime(validation.validated_at)}
                        </dd>
                    </div>
                )}
            </dl>
            {validation.notes.length > 0 && (
                <ul className="text-muted-foreground flex flex-col gap-1 text-[11.5px] leading-[1.5]">
                    {validation.notes.map((note) => (
                        <li key={note} className="flex items-start gap-1.5">
                            <span aria-hidden>·</span>
                            <span>{note}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
