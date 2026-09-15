<?php

namespace App\Http\Controllers\Embed;

use App\Http\Controllers\Controller;
use App\Models\EmbeddedSigningSession;
use App\Models\Organization;
use App\Services\Embed\AllowedOrigins;
use App\Services\Embed\EmbedFeature;
use App\Services\Embed\EmbedFrame;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Página do widget (`embed.show`, docs/fase-3/widget-embutido.md §3.1).
 *
 * O GET não autentica nada e não revela o documento: devolve só a casca do widget com a
 * origem que pode enquadrá-la, os endereços das ações e os limites de tela. O token de uso
 * único vem no FRAGMENTO da URL — o navegador não o envia ao servidor — e o widget o troca por
 * um token de execução (`embed.exchange`). Por isso esta página responde igual para sessão
 * nova, usada, vencida ou revogada: quem diz o que aconteceu é a troca, e só para quem tem o
 * token certo.
 *
 * Só aqui a resposta recebe `frame-ancestors {origem exata}` (EmbedFrame + SecurityHeaders),
 * e só se a origem continua cadastrada na organização. Sessão inexistente, flag desligada ou
 * origem descadastrada: 404 com `X-Frame-Options: DENY` e `frame-ancestors 'none'`.
 */
class EmbedPageController extends Controller
{
    public function show(Request $request, string $session): Response
    {
        abort_unless(EmbedFeature::globallyEnabled(), 404);

        /** @var EmbeddedSigningSession|null $embedded */
        $embedded = EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $session)->first();
        /** @var Organization|null $organization */
        $organization = $embedded === null ? null : Organization::query()->whereKey($embedded->organization_id)->first();
        $origin = $embedded === null ? null : AllowedOrigins::normalize($embedded->allowed_origin);

        if ($embedded === null
            || $organization === null
            || $origin === null
            || ! EmbedFeature::enabled($organization)
            || ! AllowedOrigins::allows($organization, $origin)) {
            return Inertia::render('embed/sign', self::invalidProps())
                ->toResponse($request)
                ->setStatusCode(404);
        }

        EmbedFrame::allowFramingBy($request, $origin);

        $parameters = ['session' => $embedded->ulid];

        $response = Inertia::render('embed/sign', [
            'invalid' => false,
            'session_id' => $embedded->ulid,
            'parent_origin' => $origin,
            'protocol_version' => EmbedFrame::PROTOCOL_VERSION,
            'endpoints' => [
                'exchange' => route('embed.exchange', $parameters, false),
                'state' => route('embed.state', $parameters, false),
                'otp_send' => route('embed.otp.send', $parameters, false),
                'otp_verify' => route('embed.otp.verify', $parameters, false),
                'pin' => route('embed.pin.verify', $parameters, false),
                'complete' => route('embed.complete', $parameters, false),
                'refuse' => route('embed.refuse', $parameters, false),
            ],
            'frame' => self::frameLimits(),
            'confirm_delay_ms' => max(0, (int) config('assinavelox.embedded_signing.confirm_delay_ms', 800)),
            'legal' => [
                'terms_url' => route('legal.terms'),
                'privacy_url' => route('legal.privacy'),
            ],
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public static function invalidProps(): array
    {
        return [
            'invalid' => true,
            'session_id' => null,
            'parent_origin' => null,
            'protocol_version' => EmbedFrame::PROTOCOL_VERSION,
            'endpoints' => null,
            'frame' => self::frameLimits(),
            'confirm_delay_ms' => 0,
            'legal' => [
                'terms_url' => route('legal.terms'),
                'privacy_url' => route('legal.privacy'),
            ],
        ];
    }

    /**
     * @return array{min_width: int, min_height: int}
     */
    private static function frameLimits(): array
    {
        return [
            'min_width' => max(200, (int) config('assinavelox.embedded_signing.min_frame_width', 320)),
            'min_height' => max(200, (int) config('assinavelox.embedded_signing.min_frame_height', 420)),
        ];
    }
}
