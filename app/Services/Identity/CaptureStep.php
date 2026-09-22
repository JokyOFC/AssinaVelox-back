<?php

namespace App\Services\Identity;

use App\Models\SigningSession;
use App\Services\Signing\SignerContext;

/**
 * Props da etapa de captura na página pública (`pages/sign/show.tsx`, chave
 * `identity_capture`). Contrato para a integração com `SignerPageProps` (fora da área C-ID):
 *
 *     'identity_capture' => app(CaptureStep::class)->props($context, $session),
 *
 * `null` quando a flag está desligada, o papel não registra aceite ou nada foi exigido — com
 * isso a página é exatamente a de hoje. Sem sessão (tela `identify`) o bloco vem com
 * `captured = false` e sem URL de envio: fotos só depois do código.
 */
final class CaptureStep
{
    public const NOTICE = 'Estas fotos ficam anexadas ao registro do seu aceite como imagens enviadas por você. '
        .'Elas não são usadas para verificar sua identidade: não há comparação entre rostos, '
        .'análise da imagem nem leitura automática do documento.';

    /**
     * Fase 4 §4.1: para quem tem a verificação facial com documento exigida, o aviso acima
     * seria falso — as fotos SÃO enviadas a um provedor externo, que compara a foto tirada na
     * hora com a foto do documento. Este é o aviso usado nesse caso (`%s` = nome do provedor);
     * a constante original fica intacta para todo mundo que não tem a exigência.
     */
    public const VERIFICATION_NOTICE = 'Estas fotos ficam anexadas ao registro do seu aceite e, porque quem enviou o '
        .'documento exigiu a verificação facial com documento, são enviadas ao provedor %s, que compara a foto '
        .'tirada na hora com a foto do documento e devolve um resultado. A plataforma não compara as imagens: ela '
        .'envia as fotos e registra a resposta do provedor.';

    public function __construct(
        private readonly IdentityCaptures $captures,
        private readonly IdentityVerifications $verifications,
    ) {}

    public static function verificationNotice(string $providerLabel): string
    {
        return sprintf(self::VERIFICATION_NOTICE, $providerLabel);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function props(SignerContext $context, ?SigningSession $session): ?array
    {
        // Só fotos: o vídeo curto (F-VIDEO) tem etapa própria, `VideoStep`.
        if (! $this->captures->photoRequiredFor($context)) {
            return null;
        }

        $current = $session === null ? collect() : $this->captures->currentFor($session);
        $items = [];

        foreach ($this->captures->requiredKinds($context->recipient) as $kind) {
            $capture = $current->get($kind->value);

            $items[] = [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'instructions' => $kind->instructions(),
                'facing_mode' => $kind->facingMode(),
                'required' => true,
                'captured' => $capture !== null,
                'captured_at' => $capture?->captured_at->toIso8601String(),
                'width' => $capture?->width,
                'height' => $capture?->height,
                // POST multipart `image` (+ `source` = camera|upload). Só com sessão.
                'upload_url' => $session === null ? null : route('sign.capture.store', ['token' => $context->token, 'kind' => $kind->value]),
            ];
        }

        return [
            'required' => true,
            'complete' => $items !== [] && collect($items)->every(fn (array $item): bool => $item['captured']),
            'title' => 'Fotos para o registro do aceite',
            'items' => $items,
            'accept' => ['image/jpeg', 'image/png'],
            'max_upload_kb' => (int) config('assinavelox.capture.max_upload_kb', 8192),
            'retention_days' => (int) config('assinavelox.capture.retention_days', 180),
            // Fase 4 §4.1: com a verificação facial exigida, as fotos saem para o provedor — e o
            // aviso diz isso. Sem a exigência (ou com a flag desligada), o aviso de sempre.
            'notice' => $this->verifications->requiredFor($context)
                ? self::verificationNotice($this->verifications->provider()->label())
                : self::NOTICE,
        ];
    }
}
