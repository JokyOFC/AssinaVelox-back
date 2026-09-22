<?php

namespace App\Services\Identity;

use App\Models\SigningSession;
use App\Services\Identity\Models\IdentityVerification;
use App\Services\Signing\SignerContext;

/**
 * Props da etapa de verificação facial com documento na página pública (`pages/sign/show.tsx`,
 * chave `identity_verification`; Fase 4 §4.1, docs/fase-4/verificacao-facial.md).
 *
 * `null` quando a flag está desligada, o papel não registra aceite ou a verificação não foi
 * exigida desta pessoa — e aí a chave nem aparece na página (`SignerPageProps`): props
 * idênticas às de antes. Sem sessão (tela `identify`) o bloco vem sem URLs e sem envio
 * possível: as fotos, e o envio delas, só depois do código.
 *
 * Os textos só REPETEM o que o provedor informou e o nomeiam ("Verifiky informou: aprovado");
 * a plataforma nunca afirma que verificou alguém. Resultado do simulador vem rotulado
 * "(simulado) — nenhuma imagem foi analisada". O consentimento é marcado antes do envio e a
 * versão do texto (SHA-256 do texto exibido, que cita o provedor) vai na linha e na trilha.
 */
final class VerificationStep
{
    public const TITLE = 'Verificação facial com documento';

    /** `%s` = nome do provedor. */
    public const CONSENT = 'Autorizo o envio das fotos do meu rosto e do meu documento ao provedor %s, que compara a foto '
        .'tirada na hora com a foto do documento e devolve um resultado. Sei que o resultado informado pelo provedor '
        .'fica guardado como evidência do meu aceite e que as fotos seguem o prazo de guarda já informado.';

    /** `%s` = nome do provedor. */
    public const NOTICE = 'As fotos do seu rosto e do seu documento (frente e verso) são enviadas ao provedor %s, que '
        .'compara a foto tirada na hora com a foto do documento e devolve um resultado. A plataforma não compara as '
        .'imagens: ela envia as fotos e registra a resposta do provedor, que fica guardada como evidência do seu '
        .'aceite. As fotos continuam guardadas de forma cifrada pelo prazo já informado e não aparecem no PDF assinado.';

    public const SIMULATED_SUFFIX = ' (simulado) — nenhuma imagem foi analisada';

    public const POLL_INTERVAL_MS = 3000;

    public function __construct(private readonly IdentityVerifications $verifications) {}

    public static function consentText(string $providerLabel): string
    {
        return sprintf(self::CONSENT, $providerLabel);
    }

    /** Versão do texto de consentimento: SHA-256 do texto exibido (que cita o provedor). */
    public static function consentVersion(string $providerLabel): string
    {
        return hash('sha256', self::consentText($providerLabel));
    }

    public static function notice(string $providerLabel): string
    {
        return sprintf(self::NOTICE, $providerLabel);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function documentTypes(): array
    {
        return array_map(
            static fn (string $type): array => ['value' => $type, 'label' => IdentityVerifications::documentTypeLabel($type)],
            IdentityVerifications::documentTypes(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function props(SignerContext $context, ?SigningSession $session): ?array
    {
        if (! $this->verifications->requiredFor($context)) {
            return null;
        }

        $recipient = $context->recipient;
        $latest = $this->verifications->latestFor($recipient);

        // Só o prazo: montar a página nunca espera uma chamada externa (o GET da etapa consulta).
        $latest = $latest === null ? null : $this->verifications->refresh($latest, poll: false);

        $provider = $this->verifications->provider();
        // Quem vai receber o PRÓXIMO envio (consentimento e aviso) é o adaptador corrente; a
        // mensagem de estado nomeia quem respondeu a tentativa gravada.
        $label = $provider->label();
        $answeredBy = $this->verifications->providerLabel($latest?->provider);

        $capturesComplete = $session !== null && $this->verifications->missingCaptures($session) === [];
        $capturesChanged = $latest !== null && $latest->isApproved() && $session !== null
            && ! $this->verifications->capturesMatch($latest, $session);

        $used = $this->verifications->attemptsUsed($recipient);
        $max = IdentityVerifications::maxAttempts();
        $left = max(0, $max - $used);
        $status = $latest?->status->value ?? 'none';
        $inFlight = $latest?->isInFlight() ?? false;

        return [
            'required' => true,
            'status' => $status,
            'status_label' => $latest === null ? 'Ainda não enviada' : $latest->status->label(),
            'message' => self::message($latest, $answeredBy),
            // Mensagem que o adaptador devolveu para a pessoa (só nas inconclusivas), ou null.
            'provider_message' => $latest !== null && $latest->status === VerificationStatus::Inconclusive ? $latest->providerMessage() : null,
            'provider_label' => $label,
            'simulated' => $latest === null ? $provider->isSimulated() : $latest->isSimulated(),
            'document_types' => self::documentTypes(),
            'document_type' => $latest?->document_type,
            'attempts_used' => $used,
            'max_attempts' => $max,
            'attempts_left' => $left,
            'captures_complete' => $capturesComplete,
            // Aprovada, mas alguma foto foi refeita depois: o provedor comparou outras imagens.
            'captures_changed' => $capturesChanged,
            'can_submit' => $session !== null && $capturesComplete && $left > 0 && ! $inFlight
                && ($status !== VerificationStatus::Approved->value || $capturesChanged),
            'consent' => [
                'version' => self::consentVersion($label),
                'text' => self::consentText($label),
            ],
            'notice' => self::notice($label),
            // POST `document_type` + `consent`; GET atualiza (consulta o provedor quando pendente). Só com sessão.
            'urls' => [
                'store' => $session === null ? null : route('sign.identity_verification.store', ['token' => $context->token]),
                'show' => $session === null ? null : route('sign.identity_verification.show', ['token' => $context->token]),
            ],
            'poll_interval_ms' => self::POLL_INTERVAL_MS,
        ];
    }

    /**
     * Explicação humana do estado atual — sempre repetindo o provedor, nunca afirmando por ele.
     */
    public static function message(?IdentityVerification $verification, string $providerLabel): ?string
    {
        if ($verification === null) {
            return null;
        }

        $simulated = $verification->isSimulated() ? self::SIMULATED_SUFFIX : '';

        return match ($verification->status) {
            VerificationStatus::Queued => sprintf('Fotos recebidas. O envio ao provedor %s está na fila.', $providerLabel),
            VerificationStatus::Pending => sprintf('%s está analisando as fotos. O resultado aparece aqui em instantes.', $providerLabel),
            VerificationStatus::Approved => sprintf('%s informou: aprovado — a foto tirada na hora corresponde à do documento.%s', $providerLabel, $simulated),
            VerificationStatus::Rejected => self::rejectedMessage($verification, $providerLabel, $simulated),
            VerificationStatus::Expired => sprintf('%s informou: expirado — o prazo da análise terminou. Envie as fotos de novo.%s', $providerLabel, $simulated),
            VerificationStatus::Inconclusive => 'Não foi possível concluir a verificação. Isso não é uma reprovação: você pode enviar as fotos de novo.',
        };
    }

    private static function rejectedMessage(IdentityVerification $verification, string $providerLabel, string $simulated): string
    {
        if ($verification->reason_code === 'face_mismatch' || $verification->faceMatch() === false) {
            return sprintf('%s informou: reprovado — a foto tirada na hora não corresponde à do documento.%s', $providerLabel, $simulated);
        }

        $reason = $verification->provider_result['reason'] ?? null;

        return is_string($reason) && trim($reason) !== ''
            ? sprintf('%s informou: reprovado. Motivo informado pelo provedor: “%s”.%s', $providerLabel, trim($reason), $simulated)
            : sprintf('%s informou: reprovado.%s', $providerLabel, $simulated);
    }
}
