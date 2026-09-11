<?php

namespace App\Services\Branding;

use App\Models\Organization;
use App\Services\Branding\Contracts\NoVerifiedSenderDomains;
use App\Services\Branding\Contracts\VerifiedSenderDomains;

/**
 * Remetente e Reply-To dos e-mails ao participante (roadmap §2.8; docs/fase-2/branding.md §5).
 *
 * - **Padrão:** o e-mail sai do remetente da plataforma (`mail.from`), sem mudança.
 * - **Reply-To:** com a marca ativa e um `reply_to_email` salvo, a resposta do participante
 *   vai para a organização — não para a caixa da plataforma.
 * - **Remetente próprio (`From`):** só quando `sender_email` pertence a um domínio
 *   VERIFICADO no provedor ({@see VerifiedSenderDomains}, contrato com o C-CAN). Sem essa
 *   verificação — o padrão hoje, porque a API do provedor não tem documentação — o `From`
 *   continua o da plataforma.
 */
class ParticipantMailSender
{
    public function __construct(private readonly BrandingPresenter $presenter) {}

    /**
     * @return array{from_address: string|null, from_name: string|null, reply_to: string|null, reply_to_name: string|null}
     */
    public function resolve(?Organization $organization): array
    {
        $none = ['from_address' => null, 'from_name' => null, 'reply_to' => null, 'reply_to_name' => null];
        $branding = $this->presenter->active($organization);

        if ($branding === null || $organization === null) {
            return $none;
        }

        $name = BrandingPresenter::displayName($branding, $organization);
        $replyTo = self::cleanAddress($branding->reply_to_email);
        $sender = self::cleanAddress($branding->sender_email);
        $fromAddress = null;

        if ($sender !== null && $this->domains()->isVerified($organization, self::domainOf($sender))) {
            $fromAddress = $sender;
        }

        return [
            'from_address' => $fromAddress,
            'from_name' => $fromAddress !== null ? $name : null,
            'reply_to' => $replyTo,
            'reply_to_name' => $replyTo !== null ? $name : null,
        ];
    }

    /**
     * Situação do remetente próprio para a tela de marca.
     *
     * @return array{custom_sender_active: bool, domain: string|null, domain_verified: bool}
     */
    public function senderStatus(Organization $organization, ?string $senderEmail): array
    {
        $sender = self::cleanAddress($senderEmail);
        $domain = $sender !== null ? self::domainOf($sender) : null;
        $verified = $domain !== null && $this->domains()->isVerified($organization, $domain);

        return [
            'custom_sender_active' => $verified && BrandingFeature::enabled($organization),
            'domain' => $domain,
            'domain_verified' => $verified,
        ];
    }

    public static function domainOf(string $address): string
    {
        return mb_strtolower((string) substr((string) strrchr($address, '@'), 1));
    }

    /** Defesa em profundidade contra injeção de cabeçalho: nada de CR/LF ou endereço inválido. */
    private static function cleanAddress(?string $address): ?string
    {
        $address = trim((string) $address);

        if ($address === '' || preg_match('/[\r\n]/', $address) === 1 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_strtolower($address);
    }

    private function domains(): VerifiedSenderDomains
    {
        return app()->bound(VerifiedSenderDomains::class)
            ? app(VerifiedSenderDomains::class)
            : new NoVerifiedSenderDomains;
    }
}
