<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EmbeddedSigningSession;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Api\ApiFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sessão de assinatura embutida na API v1 (docs/fase-3/widget-embutido.md §2.2).
 *
 * `url` (com o token de uso único no fragmento) só existe na resposta de criação. Nunca saem:
 * digest de token, estado da sessão, IP ou user-agent de quem abriu.
 *
 * @mixin EmbeddedSigningSession
 */
class EmbeddedSigningSessionResource extends JsonResource
{
    private ?string $url = null;

    public function withUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmbeddedSigningSession $session */
        $session = $this->resource;

        $envelopeUlid = Envelope::withoutOrganizationScope()->whereKey($session->envelope_id)->value('ulid');
        $recipientUlid = Recipient::withoutOrganizationScope()->whereKey($session->recipient_id)->value('ulid');

        $data = [
            'id' => $session->ulid,
            'object' => 'embedded_signing_session',
            'envelope_id' => is_string($envelopeUlid) ? $envelopeUlid : null,
            'recipient_id' => is_string($recipientUlid) ? $recipientUlid : null,
            'origin' => $session->allowed_origin,
            // pending | active | completed | refused | expired | revoked | closed (a pessoa
            // respondeu por outro caminho, como o link do e-mail, ou foi encerrada)
            'status' => $session->status(),
            'expires_at' => ApiFormat::date($session->expires_at),
            'used_at' => ApiFormat::date($session->used_at),
            'completed_at' => ApiFormat::date($session->completed_at),
            'revoked_at' => ApiFormat::date($session->revoked_at),
            'created_at' => ApiFormat::date($session->created_at),
        ];

        if ($this->url !== null) {
            // URL de uso único para o iframe; só na resposta de criação.
            $data['url'] = $this->url;
        }

        return $data;
    }
}
