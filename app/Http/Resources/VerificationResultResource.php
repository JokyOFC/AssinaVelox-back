<?php

namespace App\Http\Resources;

use App\Models\Envelope;
use App\Services\Verification\PublicVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resultado da verificação pública (ROUTES §2.19 `VerifyShowProps['result']`).
 *
 * O recurso é deliberadamente fino: quem decide **o que pode sair** é
 * {@see PublicVerification::result()}, um único lugar auditável. Montar as props direto no
 * controller convidaria a "só mais um campo" — e o §4.3 é uma lista de proibições, não de
 * sugestões.
 *
 * @mixin Envelope
 */
class VerificationResultResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(PublicVerification::class)->result($this->resource);
    }
}
