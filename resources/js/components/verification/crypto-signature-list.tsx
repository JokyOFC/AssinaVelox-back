import { FileSignature } from 'lucide-react';
import {
    CertificateFacts,
    TestCertificateNotice,
} from '@/components/certificates/certificate-facts';
import { Badge } from '@/components/ui/badge';
import { formatDate, formatDateTime, plural } from '@/lib/format';
import {
    cryptoIntegrityLabels,
    cryptoIntegrityTones,
    participantRequestTone,
} from '@/lib/labels';
import { cn } from '@/lib/utils';
import type {
    EvidenceParticipantSignature,
    PublicParticipantSignature,
} from '@/types/signatures';

/**
 * Assinaturas com o certificado do PRÓPRIO participante (Fase 2 §2.12).
 *
 * Três coisas que esta lista nunca mistura (roadmap T1): a assinatura do participante (aqui),
 * a assinatura da operadora (bloco "Certificado da operadora", à parte) e o aceite
 * eletrônico (seção "Participantes"). O perfil exibido é o que o servidor registrou — hoje
 * sempre PAdES-B-B — e o resultado técnico separa integridade, confiança da cadeia e
 * revogação, que não é verificada.
 */

const DEFAULT_PROFILE = 'PAdES-B-B';
const REVOCATION_NOT_CHECKED =
    'Não verificada: não há consulta a LCR nem a OCSP nesta versão.';

function Rows({
    rows,
    className,
}: {
    rows: [string, string | null | undefined, ('mono' | null)?][];
    className?: string;
}) {
    return (
        <dl
            className={cn(
                'grid grid-cols-[112px_1fr] gap-x-3 gap-y-1 text-[12.5px]',
                className,
            )}
        >
            {rows
                .filter(([, value]) => Boolean(value))
                .map(([label, value, style]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd
                            className={cn(
                                'min-w-0 leading-[1.5] break-words',
                                style === 'mono' &&
                                    'font-mono text-[11.5px] break-all',
                            )}
                        >
                            {value}
                        </dd>
                    </div>
                ))}
        </dl>
    );
}

function ListIntro({ profile }: { profile?: string | null }) {
    return (
        <div className="flex items-start gap-2">
            <FileSignature className="text-primary mt-0.5 size-4 shrink-0" />
            <div className="min-w-0">
                <p className="text-[13px] font-semibold">
                    Assinaturas com o certificado do próprio participante
                </p>
                <p className="text-muted-foreground mt-0.5 text-[12px] leading-[1.5]">
                    Cada uma identifica o titular do certificado usado e se soma
                    ao aceite eletrônico do participante, sem substituí-lo. Não
                    é a assinatura da operadora. Perfil:{' '}
                    {profile || DEFAULT_PROFILE}.
                </p>
            </div>
        </div>
    );
}

/** Página de evidências (autenticada): titular, CPF mascarado, série, impressão digital. */
export function ParticipantSignatureList({
    signatures,
    profile,
    revocationLabel,
    className,
}: {
    signatures: EvidenceParticipantSignature[];
    profile?: string | null;
    /** `validation.revocation_label` do servidor, quando houver. */
    revocationLabel?: string | null;
    className?: string;
}) {
    if (signatures.length === 0) {
        return null;
    }

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <ListIntro profile={profile} />
            <ol className="flex flex-col gap-3">
                {signatures.map((signature) => (
                    <li
                        key={signature.id}
                        className="border-border rounded-lg border p-3.5"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-[13px] leading-[1.4] font-semibold">
                                    {signature.label}
                                </p>
                                <p className="text-muted-foreground mt-0.5 text-[12px]">
                                    {signature.kind_label}
                                    {signature.recipient.name &&
                                        ` · participante: ${signature.recipient.name}`}
                                </p>
                            </div>
                            <Badge
                                variant={participantRequestTone(
                                    signature.status,
                                )}
                            >
                                {signature.status_label}
                            </Badge>
                        </div>

                        {signature.certificate?.is_test && (
                            <TestCertificateNotice
                                label={signature.certificate.kind_label}
                                className="mt-2.5"
                            />
                        )}

                        {signature.certificate && (
                            <CertificateFacts
                                certificate={signature.certificate}
                                className="mt-2.5"
                            />
                        )}

                        <Rows
                            className="mt-1"
                            rows={[
                                [
                                    'Consentimento',
                                    signature.consent
                                        ? `Versão ${signature.consent.version}${signature.consent.consented_at ? ` · ${formatDateTime(signature.consent.consented_at)}` : ''}`
                                        : null,
                                ],
                                [
                                    'Assinada em',
                                    signature.signed_at
                                        ? formatDateTime(signature.signed_at)
                                        : null,
                                ],
                                [
                                    'Prazo',
                                    !signature.signed_at &&
                                    signature.window_expires_at
                                        ? `até ${formatDateTime(signature.window_expires_at)}`
                                        : null,
                                ],
                            ]}
                        />

                        {signature.failure?.message && (
                            <p className="border-warning-border bg-warning-bg text-warning mt-2.5 rounded-[10px] border p-2.5 text-[12px] leading-[1.45]">
                                {signature.failure.message}
                            </p>
                        )}

                        {signature.documents.length > 0 && (
                            <div className="mt-3">
                                <p className="text-muted-foreground text-[11px] font-semibold tracking-[.08em] uppercase">
                                    {signature.documents.length > 1
                                        ? 'Por arquivo'
                                        : 'Resultado no arquivo final'}
                                </p>
                                <ul className="mt-1.5 flex flex-col gap-2">
                                    {signature.documents.map((item) => (
                                        <li
                                            key={`${item.document_id}-${item.revision_index}`}
                                            className="bg-sidebar rounded-md p-2.5"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2 text-[12.5px]">
                                                <span className="min-w-0 font-semibold">
                                                    {item.position}.{' '}
                                                    {item.name ??
                                                        `Arquivo ${item.position}`}
                                                    <span className="text-muted-foreground font-normal">
                                                        {' · '}revisão nº{' '}
                                                        {item.revision_index}
                                                    </span>
                                                </span>
                                                <Badge
                                                    variant={
                                                        cryptoIntegrityTones[
                                                            item.integrity
                                                        ]
                                                    }
                                                >
                                                    {
                                                        cryptoIntegrityLabels[
                                                            item.integrity
                                                        ]
                                                    }
                                                </Badge>
                                            </div>
                                            <Rows
                                                className="mt-1.5"
                                                rows={[
                                                    [
                                                        'Integridade',
                                                        item.integrity ===
                                                        'intact'
                                                            ? 'Íntegra e válida no arquivo final.'
                                                            : item.integrity ===
                                                                'broken'
                                                              ? 'A validação NÃO confirmou esta assinatura no arquivo final.'
                                                              : 'Resultado desta assinatura não registrado na conclusão.',
                                                    ],
                                                    [
                                                        'Cadeia',
                                                        item.trusted
                                                            ? 'Validada até uma raiz de confiança configurada nesta plataforma.'
                                                            : 'Não verificada por esta plataforma.',
                                                    ],
                                                    [
                                                        'Revogação',
                                                        revocationLabel ??
                                                            REVOCATION_NOT_CHECKED,
                                                    ],
                                                    [
                                                        'Perfil',
                                                        item.profile ??
                                                            profile ??
                                                            DEFAULT_PROFILE,
                                                    ],
                                                    [
                                                        'Assinada em',
                                                        formatDateTime(
                                                            item.signed_at,
                                                        ),
                                                    ],
                                                    [
                                                        'SHA-256',
                                                        item.signed_sha256,
                                                        'mono',
                                                    ],
                                                ]}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}

/**
 * Verificação pública: só assinaturas APLICADAS, com o nome mascarado — sem CPF, série,
 * impressão digital ou e-mail (docs/verificacao-publica.md §1.4).
 */
export function PublicParticipantSignatureList({
    signatures,
    profile,
    revocationLabel,
    className,
}: {
    signatures: PublicParticipantSignature[];
    profile?: string | null;
    revocationLabel?: string | null;
    className?: string;
}) {
    if (signatures.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'border-border flex flex-col gap-3 rounded-lg border p-3.5',
                className,
            )}
        >
            <ListIntro profile={profile} />
            <ol className="flex flex-col gap-2.5">
                {signatures.map((signature, index) => (
                    <li
                        key={`${signature.holder_name_masked}-${index}`}
                        className="border-muted flex flex-col gap-2 border-t pt-2.5 first:border-t-0 first:pt-0"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <p className="min-w-0 text-[13px] leading-[1.4] font-semibold">
                                {signature.label}
                            </p>
                            {/* Certificado de TESTE: "válida" em verde contradiria "não tem validade jurídica". */}
                            <Badge
                                variant={
                                    signature.is_test &&
                                    signature.integrity === 'intact'
                                        ? 'neutral'
                                        : cryptoIntegrityTones[
                                              signature.integrity
                                          ]
                                }
                            >
                                {signature.is_test &&
                                signature.integrity === 'intact'
                                    ? 'Íntegra — certificado de teste'
                                    : cryptoIntegrityLabels[
                                          signature.integrity
                                      ]}
                            </Badge>
                        </div>
                        {signature.is_test ? (
                            <TestCertificateNotice
                                label={signature.certificate_label}
                            />
                        ) : (
                            <p className="text-muted-foreground text-[12px] leading-[1.5]">
                                {signature.certificate_label}
                            </p>
                        )}
                        <Rows
                            rows={[
                                ['Titular', signature.holder_name_masked],
                                ['Emissor', signature.issuer_cn],
                                [
                                    'Validade',
                                    signature.valid_from && signature.valid_to
                                        ? `${formatDate(signature.valid_from)} → ${formatDate(signature.valid_to)}`
                                        : null,
                                ],
                                [
                                    'Assinada em',
                                    signature.signed_at
                                        ? formatDateTime(signature.signed_at)
                                        : null,
                                ],
                                [
                                    'Arquivos',
                                    signature.documents_count > 0
                                        ? plural(
                                              signature.documents_count,
                                              'arquivo assinado',
                                              'arquivos assinados',
                                          )
                                        : null,
                                ],
                                ['Integridade', signature.integrity_label],
                                ['Cadeia', signature.chain_trust_label],
                                [
                                    'Revogação',
                                    revocationLabel ?? REVOCATION_NOT_CHECKED,
                                ],
                            ]}
                        />
                    </li>
                ))}
            </ol>
        </div>
    );
}
