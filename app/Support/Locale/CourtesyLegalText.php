<?php

namespace App\Support\Locale;

use App\Enums\AcceptanceAction;
use App\Enums\DeliveryChannel;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityFeatures;
use App\Services\Identity\IdentityVerifications;
use App\Services\Signing\Channels\ChannelAvailability;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\ConsentText;

/**
 * Os textos jurídicos da página pública num idioma (Fase 3 §3.3 — F-I18N,
 * docs/fase-3/multilingue.md §6): declaração de aceite, rótulo da caixa, aviso de privacidade e
 * aviso de conclusão, montados a partir de `lang/{idioma}/signer_legal.php`.
 *
 * O que vale como evidência é SEMPRE o texto de referência em PT-BR gerado por
 * {@see ConsentText} — é ele que vai para `consent_statement`, para o snapshot autorizado e para
 * o PDF de evidências. Em `en`/`es` este serviço produz a TRADUÇÃO DE CORTESIA mostrada ao lado,
 * marcada como tal na tela, com o texto de referência a um clique.
 *
 * Em PT-BR o resultado é idêntico ao do ConsentText, palavra por palavra (ConsentParityTest):
 * é assim que se garante que as traduções têm a mesma estrutura — mesmas variantes (papel,
 * quantidade de documentos, certificado da operadora) e mesmos complementos da onda B (canal do
 * código, PIN, CPF, fotos, canal simulado).
 */
final class CourtesyLegalText
{
    /**
     * @param  list<array{document: Document, version: DocumentVersion}>  $documents
     */
    public static function statement(
        SignerLocale $locale,
        Envelope $envelope,
        Recipient $recipient,
        Organization $organization,
        string $documentSha256,
        ?bool $withCertificate = null,
        array $documents = [],
    ): string {
        $withCertificate ??= ConsentText::operatorCertificateActive();

        $action = $recipient->role->acceptanceAction() ?? AcceptanceAction::Sign;
        $count = max(1, count($documents));
        $multi = $count > 1;
        $size = $multi ? 'multi' : 'single';
        $key = $action->value;
        $wave = self::waveB($recipient);

        $lines = [
            self::text($locale, 'version_line', [
                'heading' => self::text($locale, 'heading.'.$key),
                'version' => ConsentText::versionFor($envelope, $recipient, $count),
            ]),
            '',
            self::text($locale, 'intro', [
                'name' => $recipient->name,
                'email' => (string) $recipient->masked_email,
                'capacity' => self::text($locale, 'capacity.'.$key),
            ]),
            '',
            self::text($locale, 'item1.'.$key.'.'.$size, [
                'title' => $envelope->title,
                'sender' => $organization->legal_name ?: $organization->name,
                'sha' => $documentSha256,
                'count' => (string) $count,
            ]),
        ];

        if ($multi) {
            foreach ($documents as $index => $row) {
                $lines[] = self::text($locale, 'document_line', [
                    'index' => (string) ($index + 1),
                    'document' => (string) $row['document']->name,
                    'sha' => (string) $row['version']->sha256,
                ]);
            }
        }

        $lines[] = self::text($locale, 'item2.'.$key);
        $lines[] = self::text($locale, 'item3', [
            'subject' => self::text($locale, 'subject.'.($action === AcceptanceAction::Approve ? 'approval' : 'acceptance')),
            'method' => self::method($locale, $wave),
            'simulated' => $wave !== null && $wave['simulated'] ? self::simulatedNote($locale, $wave['channel']) : '',
            'version_of' => self::text($locale, 'version_of.'.$size),
            'extras' => self::extras($locale, $wave),
        ]);
        $lines[] = self::text($locale, 'item4.'.($withCertificate ? 'certificate' : 'no_certificate').'.'.$size, [
            'operator' => ConsentText::operatorLegalName(),
            'url' => ConsentText::verificationUrl(),
            'code' => (string) ($envelope->formatted_verification_code ?? '—'),
        ]);
        $lines[] = self::text($locale, 'item5.'.$key);
        $lines[] = self::text($locale, 'item6.'.$key);

        return implode("\n", $lines);
    }

    public static function checkboxLabel(SignerLocale $locale, Envelope $envelope, ?Recipient $recipient = null, int $documentCount = 1): string
    {
        $action = $recipient?->role->acceptanceAction() ?? AcceptanceAction::Sign;

        return self::text($locale, 'label.'.$action->value.'.'.($documentCount > 1 ? 'multi' : 'single'), [
            'title' => $envelope->title,
            'count' => (string) $documentCount,
            'code' => self::codePhrase($locale, self::waveB($recipient)),
        ]);
    }

    public static function privacySummary(SignerLocale $locale, Organization $organization, ?RecipientRole $role = null, ?Recipient $recipient = null): string
    {
        return self::text($locale, 'privacy_summary', [
            'organization' => $organization->name,
            'purpose' => self::text($locale, 'privacy_purpose.'.self::audience($role)),
            'code' => self::codePhrase($locale, self::waveB($recipient)),
        ]);
    }

    public static function privacyNotice(SignerLocale $locale, Organization $organization, ?RecipientRole $role = null, ?Recipient $recipient = null): string
    {
        $wave = self::waveB($recipient);
        /** @var array<int, string>|string $paragraphs */
        $paragraphs = trans('signer_legal.privacy_notice.'.self::audience($role), [], $locale->value);

        $replace = [
            'organization' => $organization->name,
            'operator' => ConsentText::operatorLegalName(),
            'support' => (string) config('assinavelox.support_email', 'suporte@assinavelox.com.br'),
            'registered' => self::registered($locale, $wave),
            'not_asked' => self::notAsked($locale, $wave),
        ];

        return implode("\n\n", array_map(
            static fn (string $paragraph): string => self::fill($paragraph, $replace),
            is_array($paragraphs) ? array_values(array_filter($paragraphs, 'is_string')) : [],
        ));
    }

    public static function completionNotice(SignerLocale $locale, ?bool $withCertificate = null, bool $participantCertificateOffered = false): string
    {
        $withCertificate ??= ConsentText::operatorCertificateActive();

        if ($participantCertificateOffered) {
            return self::text($locale, 'completion.'.($withCertificate ? 'participant_certificate' : 'participant_no_certificate'))
                .self::text($locale, 'completion.participant_suffix');
        }

        return self::text($locale, 'completion.'.($withCertificate ? 'certificate' : 'no_certificate'));
    }

    // -- Peças ---------------------------------------------------------------------------

    private static function audience(?RecipientRole $role): string
    {
        return match ($role) {
            RecipientRole::Viewer => 'viewer',
            RecipientRole::Approver => 'approver',
            default => 'signer',
        };
    }

    /**
     * @param  array<string, mixed>|null  $wave
     */
    private static function codePhrase(SignerLocale $locale, ?array $wave): string
    {
        if ($wave === null) {
            return self::text($locale, 'code.email');
        }

        return self::text($locale, 'code.'.$wave['channel']->value)
            .($wave['pin'] ? self::text($locale, 'code.pin_suffix') : '')
            .($wave['simulated'] ? self::simulatedNote($locale, $wave['channel']) : '');
    }

    /**
     * @param  array<string, mixed>|null  $wave
     */
    private static function method(SignerLocale $locale, ?array $wave): string
    {
        $channel = $wave === null ? DeliveryChannel::Email : $wave['channel'];

        return self::text($locale, 'method.'.$channel->value)
            .($wave !== null && $wave['pin'] ? self::text($locale, 'method.pin_suffix') : '');
    }

    /**
     * @param  array<string, mixed>|null  $wave
     */
    private static function extras(SignerLocale $locale, ?array $wave): string
    {
        if ($wave === null) {
            return '';
        }

        $extras = '';

        if ($wave['cpf_lookup']) {
            $extras .= self::text($locale, 'extras.cpf_lookup');
        }

        if ($wave['photos'] !== [] && $wave['verification'] !== null) {
            // Fase 4 §4.1: as fotos saem para o provedor nomeado (mesma leitura de ConsentText).
            $extras .= self::text($locale, 'extras.photos_verification', [
                'photos' => self::photos($locale, $wave['photos']),
                'provider' => $wave['verification'],
            ]);
        } elseif ($wave['photos'] !== []) {
            $extras .= self::text($locale, 'extras.photos', ['photos' => self::photos($locale, $wave['photos'])]);
        }

        return $extras;
    }

    /**
     * @param  array<string, mixed>|null  $wave
     */
    private static function registered(SignerLocale $locale, ?array $wave): string
    {
        $channel = $wave === null ? DeliveryChannel::Email : $wave['channel'];

        $text = self::text($locale, 'registered.base', [
            'who' => self::text($locale, 'registered.who.'.($channel === DeliveryChannel::Email ? 'email' : 'phone')),
            'where' => self::text($locale, 'registered.where.'.$channel->value),
        ]);

        if ($wave === null) {
            return $text;
        }

        if ($wave['pin']) {
            $text .= self::text($locale, 'registered.pin');
        }

        if ($wave['cpf'] && $wave['cpf_lookup']) {
            $text .= self::text($locale, 'registered.cpf_lookup');
        } elseif ($wave['cpf']) {
            $text .= self::text($locale, 'registered.cpf');
        }

        if ($wave['photos'] !== []) {
            $days = (int) config('assinavelox.capture.retention_days', 180);

            // Fase 4 §4.1: com a verificação exigida, a cláusula das fotos nomeia o provedor.
            $text .= self::text($locale, $wave['verification'] !== null ? 'registered.photos_verification' : 'registered.photos', [
                'photos' => self::photos($locale, $wave['photos']),
                'provider' => (string) $wave['verification'],
                'retention' => $days > 0
                    ? self::text($locale, 'registered.retention_days', ['days' => (string) $days])
                    : self::text($locale, 'registered.retention_kept'),
            ]);
        }

        return $text;
    }

    /**
     * "senha, CPF, foto ou localização", tirando o que ESTE participante vai informar.
     *
     * @param  array<string, mixed>|null  $wave
     */
    private static function notAsked(SignerLocale $locale, ?array $wave): string
    {
        $items = array_values(array_filter([
            $wave !== null && $wave['pin'] ? null : self::text($locale, 'not_asked.password'),
            $wave !== null && $wave['cpf'] ? null : self::text($locale, 'not_asked.cpf'),
            $wave !== null && $wave['photos'] !== [] ? null : self::text($locale, 'not_asked.photo'),
            self::text($locale, 'not_asked.location'),
        ]));

        $last = (string) array_pop($items);

        return $items === [] ? $last : implode(', ', $items).self::text($locale, 'not_asked.or').$last;
    }

    /**
     * @param  list<string>  $kinds
     */
    private static function photos(SignerLocale $locale, array $kinds): string
    {
        return implode(', ', array_map(
            static fn (string $kind): string => self::text($locale, 'photo_kinds.'.$kind),
            $kinds,
        ));
    }

    private static function simulatedNote(SignerLocale $locale, DeliveryChannel $channel): string
    {
        return self::text($locale, 'simulated_note', ['channel' => $channel->label()]);
    }

    /**
     * Mesma leitura de {@see ConsentText::waveB()} (privado lá): o que muda nos textos para este
     * participante. `null` = só e-mail, sem PIN, sem CPF e sem foto exigida.
     *
     * `verification` (Fase 4 §4.1): nome do provedor externo quando a verificação facial com
     * documento está exigida (e a flag ligada); senão null.
     *
     * @return array{channel: DeliveryChannel, pin: bool, cpf: bool, cpf_lookup: bool, photos: list<string>, simulated: bool, verification: string|null}|null
     */
    private static function waveB(?Recipient $recipient): ?array
    {
        if ($recipient === null || $recipient->getKey() === null) {
            return null;
        }

        $channel = $recipient->auth_method->channel();
        $pin = app(SenderPins::class)->requiredFor($recipient);
        $cpf = SigningField::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->where('type', FieldType::Cpf->value)
            ->exists();

        $organization = Organization::query()->find($recipient->organization_id);
        $photos = [];

        if (IdentityFeatures::identityCapture($organization)) {
            foreach (app(IdentityCaptures::class)->requiredKinds($recipient) as $kind) {
                $photos[] = $kind->value;
            }
        }

        $verification = null;

        if ($photos !== [] && IdentityFeatures::identityVerification($organization)) {
            $verifications = app(IdentityVerifications::class);
            $verification = $verifications->requirement($recipient) !== null ? $verifications->provider()->label() : null;
        }

        if ($channel === DeliveryChannel::Email && ! $pin && ! $cpf && $photos === []) {
            return null;
        }

        return [
            'channel' => $channel,
            'pin' => $pin,
            'cpf' => $cpf,
            'cpf_lookup' => $cpf && IdentityFeatures::cpfLookup($organization),
            'photos' => $photos,
            'simulated' => $channel !== DeliveryChannel::Email
                && (app(ChannelAvailability::class)->provider($channel)?->isSimulated() ?? false),
            'verification' => $verification,
        ];
    }

    /**
     * Um modelo de `signer_legal`, com `:nome` substituído numa passada só — o valor inserido
     * (título, nome, e-mail mascarado: dado de terceiros) nunca é relido como modelo.
     *
     * @param  array<string, string>  $replace
     */
    private static function text(SignerLocale $locale, string $key, array $replace = []): string
    {
        $template = trans('signer_legal.'.$key, [], $locale->value);

        return self::fill(is_string($template) ? $template : '', $replace);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function fill(string $template, array $replace): string
    {
        if ($replace === []) {
            return $template;
        }

        $pairs = [];

        foreach ($replace as $name => $value) {
            $pairs[':'.$name] = $value;
        }

        return strtr($template, $pairs);
    }
}
