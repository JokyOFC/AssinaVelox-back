<?php

namespace App\Services\Embed;

use App\Models\EmbeddedSigningSession;
use App\Services\Identity\IdentityCaptures;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerPageProps;
use App\Services\Signing\SignerSessions;
use Illuminate\Http\Request;

/**
 * Estado da tela do widget (docs/fase-3/widget-embutido.md §3.5).
 *
 * As props são EXATAMENTE as da página pública (`SignerPageProps::build` — mesmo princípio de
 * dado mínimo por etapa, mesma declaração de aceite, mesmo token de autorização preso ao
 * snapshot), com três ajustes de escopo:
 *
 * 1. a URL do PDF de cada documento vira a rota do widget (`embed.document`), que exige o
 *    token de execução no cabeçalho — nunca uma URL `/assinar/…`;
 * 2. toda URL montada com o token do convite (downloads, comprovante, certificado, gov.br) é
 *    removida: o widget não baixa nada nem navega para fora; a cópia final chega por e-mail;
 * 3. telas que o widget não oferece (foto ou vídeo exigidos) viram `unsupported`, e a sessão
 *    que não abre mais (convite revogado, fora da vez) vira `unavailable`.
 */
final class EmbedScreenProps
{
    public function __construct(
        private readonly SignerPageProps $props,
        private readonly SignerSessions $sessions,
        private readonly IdentityCaptures $captures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(EmbeddedSigningSession $embedded, ?SignerContext $context, Request $request): array
    {
        if ($embedded->isRevoked()) {
            return self::closed($embedded, 'revoked');
        }

        if ($context === null) {
            return self::closed($embedded, 'unavailable');
        }

        $session = $context->isActive() ? $this->sessions->current($context, $request) : null;
        $props = $this->props->build($context, $request, $session);

        if (in_array($props['screen'], ['identify', 'sign'], true) && $this->captures->isRequiredFor($context)) {
            return self::closed($embedded, 'unsupported');
        }

        // Visualizador nunca recebe sessão embutida (a API recusa); se chegar aqui, não abre.
        if ($props['screen'] === 'view') {
            return self::closed($embedded, 'unavailable');
        }

        $props = $this->rewriteDocuments($props, $embedded, $context);
        /** @var array<string, mixed> $props */
        $props = self::strip($props);

        $props['token'] = null;

        if (is_array($props['receipt'] ?? null)) {
            $props['receipt']['can_download'] = false;
            $props['receipt']['download_url'] = null;
            $props['receipt']['final_pdf_url'] = null;
        }

        // O texto de conclusão promete o arquivo final por e-mail, que é o que acontece.
        $props['embed'] = self::meta($embedded);

        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    public static function closed(EmbeddedSigningSession $embedded, string $screen): array
    {
        return [
            'screen' => $screen,
            'embed' => self::meta($embedded),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(EmbeddedSigningSession $embedded): array
    {
        return [
            'session_id' => $embedded->ulid,
            'status' => $embedded->status(),
            'outcome' => $embedded->outcome,
            'expires_at' => $embedded->runtime_expires_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function rewriteDocuments(array $props, EmbeddedSigningSession $embedded, SignerContext $context): array
    {
        $url = static fn (string $documentUlid): string => route('embed.document', [
            'session' => $embedded->ulid,
            'document' => $documentUlid,
        ], false);

        if (is_array($props['documents'] ?? null)) {
            foreach ($props['documents'] as $index => $item) {
                if (is_array($item) && is_string($item['id'] ?? null)) {
                    $props['documents'][$index]['pdf_url'] = $url($item['id']);
                }
            }
        }

        if (is_array($props['document'] ?? null)) {
            $first = $context->sentDocuments()[0]['document'] ?? null;
            $props['document']['pdf_url'] = $first !== null ? $url($first->ulid) : null;
        }

        return $props;
    }

    /**
     * Remove (null) toda string montada com o marcador do contexto embutido — as URLs
     * `/assinar/{token}/…` que os serviços do fluxo público constroem.
     */
    private static function strip(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_contains($value, EmbeddedContextResolver::PLACEHOLDER_TOKEN) ? null : $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::strip($item);
            }
        }

        return $value;
    }
}
