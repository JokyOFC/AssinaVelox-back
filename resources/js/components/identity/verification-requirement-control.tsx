import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { requestJson } from '@/components/identity/http';
import type { IdentityVerificationRequirementResponse } from '@/components/identity/verification-types';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { identity_verification as identityVerificationRoute } from '@/routes/envelopes/recipients';
import type { CaptureKind } from '@/types/enums';

/**
 * Exigência de verificação facial com documento por participante (Fase 4 §4.1, flag
 * `identity_verification`) no passo de participantes do wizard.
 * `PUT envelopes.recipients.identity_verification`, por `fetch` (JSON): uma visita do Inertia
 * cancelaria a gravação automática dos participantes que estivesse no ar.
 *
 * Ligar faz o servidor acrescentar as três fotos (rosto, frente e verso) à exigência de fotos
 * do participante; a resposta traz `capture_kinds` e o controle das fotos é atualizado pelo
 * `onCaptureKindsChange`. O texto diz o que acontece: as fotos vão ao provedor NOMEADO, que
 * compara a foto tirada na hora com a foto do documento — a plataforma só registra a resposta.
 *
 * Quem decide se o controle aparece é o passo (`verificationEnabled`, papel que registra
 * aceite); aqui só a caixa e a gravação.
 */
export function VerificationRequirementControl({
    envelopeId,
    recipientId,
    recipientName,
    providerLabel,
    required,
    onSaved,
    onCaptureKindsChange,
    disabled,
}: {
    envelopeId: string;
    /** ULID; `null` enquanto o participante ainda não foi salvo. */
    recipientId: string | null;
    recipientName: string;
    /** 'Verifiky' | 'Simulador' | 'Não configurado' (`verification_provider_label`). */
    providerLabel: string;
    required: boolean;
    onSaved: (required: boolean) => void;
    /** Fotos que a exigência de captura passou a pedir (a resposta do servidor). */
    onCaptureKindsChange?: (kinds: CaptureKind[]) => void;
    disabled?: boolean;
}) {
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [optimistic, setOptimistic] = useState<boolean | null>(null);
    const current = optimistic ?? required;
    const unsaved = recipientId === null;

    const save = async (next: boolean) => {
        if (recipientId === null) {
            return;
        }

        setOptimistic(next);
        setSaving(true);
        setError(null);

        const response =
            await requestJson<IdentityVerificationRequirementResponse>(
                'PUT',
                identityVerificationRoute({
                    envelope: envelopeId,
                    recipient: recipientId,
                }).url,
                { required: next },
            );

        setSaving(false);
        setOptimistic(null);

        if (response.ok && response.body) {
            onSaved(Boolean(response.body.required));

            if (Array.isArray(response.body.capture_kinds)) {
                onCaptureKindsChange?.(response.body.capture_kinds);
            }

            return;
        }

        setError(
            response.network
                ? 'Não foi possível salvar agora. Verifique a conexão e tente de novo.'
                : (Object.values(response.body?.errors ?? {})[0]?.[0] ??
                      response.body?.message ??
                      'Não foi possível salvar a exigência de verificação facial com documento.'),
        );
    };

    return (
        <div className="flex flex-col gap-2">
            <label className="flex items-start gap-2.5 text-[13px]">
                <Checkbox
                    checked={current}
                    disabled={disabled || unsaved || saving}
                    onCheckedChange={(value) => void save(value === true)}
                    className="mt-0.5"
                    aria-label={`Exigir verificação facial com documento de ${recipientName || 'este participante'}`}
                />
                <span className="min-w-0">
                    <span className="flex items-center gap-1.5 font-semibold">
                        <ShieldCheck className="text-primary size-3.5" />
                        Verificação facial com documento pelo provedor{' '}
                        {providerLabel}
                        {saving && <Spinner className="size-3" />}
                    </span>
                    <span className="text-muted-foreground block text-[12px] leading-[1.45]">
                        {unsaved
                            ? 'Salve o participante para escolher.'
                            : `As três fotos (rosto, frente e verso do documento) passam a ser obrigatórias e são enviadas ao provedor ${providerLabel}, que compara a foto tirada na hora com a foto do documento; ${recipientName || 'o participante'} só conclui com o resultado “aprovado” informado por ele.`}
                    </span>
                </span>
            </label>

            <InputError message={error ?? undefined} />
        </div>
    );
}
