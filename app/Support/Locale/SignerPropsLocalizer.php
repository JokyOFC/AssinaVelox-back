<?php

namespace App\Support\Locale;

use App\Services\Signing\Certificates\ParticipantCertificateService;
use App\Services\Signing\ConsentText;
use App\Services\Signing\SignerContext;

/**
 * Traduz as props de `sign/show` que o SERVIDOR escreve (Fase 3 §3.3 — F-I18N,
 * docs/fase-3/multilingue.md §5). Os textos da interface ficam no front
 * (`resources/js/i18n`); aqui ficam os que nascem em PHP: rótulo do botão principal, papel,
 * aviso de privacidade, textos jurídicos, aviso da cópia, rótulos do comprovante, textos das
 * etapas de foto e vídeo e os avisos do canal do código.
 *
 * Em PT-BR devolve as props intactas. Em `en`/`es`:
 *
 * - a declaração de aceite (`consent.statement`, `consent_text`) e a versão continuam as de
 *   REFERÊNCIA — são elas que o servidor confere no aceite e grava como evidência. A tradução
 *   de cortesia vai AO LADO, em `consent.translation`;
 * - o aviso de privacidade é trocado pela tradução de cortesia, e o texto de referência vai em
 *   `privacy.reference` para a página oferecer "ver o original em português";
 * - nome da organização, título, nomes, motivo de recusa e qualquer texto do remetente ou do
 *   participante saem como vieram (dado, não texto nosso).
 */
final class SignerPropsLocalizer
{
    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public function localize(array $props, SignerContext $context, SignerLocale $locale): array
    {
        if ($locale->isReference()) {
            return $props;
        }

        $t = static fn (mixed $value): mixed => is_string($value) ? SignerMessageCatalog::translate($value, $locale) : $value;

        if (is_array($props['action'] ?? null)) {
            $type = (string) ($props['action']['type'] ?? '');
            $props['action']['label'] = self::ui($locale, 'action.label.'.$type, $props['action']['label'] ?? null);

            if (($props['action']['button_label'] ?? null) !== null) {
                $props['action']['button_label'] = self::ui($locale, 'action.button.'.$type, $props['action']['button_label']);
            }
        }

        if (is_array($props['recipient'] ?? null)) {
            $props['recipient'] = self::withRoleLabel($props['recipient'], $locale);
        }

        if (is_array($props['others'] ?? null)) {
            $props['others'] = array_map(
                static fn (mixed $other): mixed => is_array($other) ? self::withRoleLabel($other, $locale) : $other,
                $props['others'],
            );
        }

        $recipient = $context->recipient;
        $organization = $context->organization;

        if (is_array($props['privacy'] ?? null)) {
            $props['privacy']['reference'] = [
                'summary' => $props['privacy']['summary'] ?? null,
                'notice' => $props['privacy']['notice'] ?? null,
            ];
            $props['privacy']['summary'] = CourtesyLegalText::privacySummary($locale, $organization, $recipient->role, $recipient);
            $props['privacy']['notice'] = CourtesyLegalText::privacyNotice($locale, $organization, $recipient->role, $recipient);
        }

        if (is_array($props['consent'] ?? null)) {
            $sent = $context->sentDocuments();
            $count = max(1, count($sent));
            $version = $context->sentVersion();
            $withCertificate = ConsentText::operatorCertificateActive();

            $props['consent']['completion_notice'] = CourtesyLegalText::completionNotice(
                $locale,
                $withCertificate,
                app(ParticipantCertificateService::class)->offeredTo($context),
            );
            $props['consent']['translation'] = [
                'locale' => $locale->value,
                'checkbox_label' => CourtesyLegalText::checkboxLabel($locale, $context->envelope, $recipient, $count),
                'statement' => CourtesyLegalText::statement(
                    $locale,
                    $context->envelope,
                    $recipient,
                    $organization,
                    (string) ($version->sha256 ?? ''),
                    $withCertificate,
                    $sent,
                ),
                // Enquanto a revisão profissional deste idioma não existir, a tela diz que é
                // tradução de cortesia (config `multilingual.reviewed_legal_locales`).
                'reviewed' => self::reviewed($locale),
            ];
        }

        if (is_array($props['copy'] ?? null)) {
            $props['copy']['notice'] = $t($props['copy']['notice'] ?? null);
        }

        // Integração I-3F: as dicas das caixas de campo (`placeholder`) nascem em PHP
        // (SignerPresentation::placeholder — sempre texto NOSSO, nunca do remetente) e o rótulo
        // padrão "Testemunha"; sem isto a caixa de assinatura dizia "Clique para assinar aqui" na
        // página em inglês. O rótulo escrito pelo remetente (`label`) sai como veio (dado).
        if (is_array($props['my_fields'] ?? null)) {
            $props['my_fields'] = array_map(static function (mixed $field) use ($t): mixed {
                if (! is_array($field)) {
                    return $field;
                }

                $field['placeholder'] = $t($field['placeholder'] ?? null);

                if (($field['type'] ?? null) === 'signature' && ($field['label'] ?? null) === 'Testemunha') {
                    $field['label'] = $t('Testemunha');
                }

                return $field;
            }, $props['my_fields']);
        }

        if (is_array($props['receipt'] ?? null)) {
            $receipt = $props['receipt'];
            $receipt['auth_label'] = $t($receipt['auth_label'] ?? null);

            if (is_string($receipt['action'] ?? null)) {
                $receipt['action_label'] = self::ui($locale, 'action.label.'.$receipt['action'], $receipt['action_label'] ?? null);
            }

            if (is_string($receipt['completion_notice'] ?? null)) {
                // Antes do fim é previsão (texto nosso); depois é o FATO registrado na verificação
                // (SignatureNarrative), traduzido pelo catálogo quando há entrada.
                $receipt['completion_notice'] = $context->envelope->status->isTerminal()
                    ? $t($receipt['completion_notice'])
                    : CourtesyLegalText::completionNotice($locale, null, app(ParticipantCertificateService::class)->offeredTo($context));
            }

            $props['receipt'] = $receipt;
        }

        if (array_key_exists('identity_capture', $props)) {
            $props['identity_capture'] = self::captureBlock($props['identity_capture'], $locale);
        }

        if (array_key_exists('identity_video', $props)) {
            $props['identity_video'] = self::videoBlock($props['identity_video'], $locale);
        }

        if (is_array($props['signer_auth'] ?? null)) {
            foreach (['method_label', 'channel_label', 'unavailable_reason', 'notice'] as $key) {
                $props['signer_auth'][$key] = $t($props['signer_auth'][$key] ?? null);
            }

            if (is_array($props['signer_auth']['pin'] ?? null) && is_string($props['signer_auth']['pin']['notice'] ?? null)) {
                $props['signer_auth']['pin']['notice'] = $t($props['signer_auth']['pin']['notice']);
            }
        }

        return $props;
    }

    /**
     * Bloco `identity_capture` (fotos) — nas props e na resposta JSON de cada envio.
     */
    public static function captureBlock(mixed $block, SignerLocale $locale): mixed
    {
        if ($locale->isReference() || ! is_array($block)) {
            return $block;
        }

        foreach (['title', 'notice'] as $key) {
            if (is_string($block[$key] ?? null)) {
                $block[$key] = SignerMessageCatalog::translate($block[$key], $locale);
            }
        }

        if (is_array($block['items'] ?? null)) {
            $block['items'] = array_map(static function (mixed $item) use ($locale): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                foreach (['label', 'instructions'] as $key) {
                    if (is_string($item[$key] ?? null)) {
                        $item[$key] = SignerMessageCatalog::translate($item[$key], $locale);
                    }
                }

                return $item;
            }, $block['items']);
        }

        return $block;
    }

    /**
     * Bloco `identity_video` — nas props e na resposta JSON do envio. A versão do consentimento
     * (`consent_version`) continua sendo o SHA-256 do texto de referência.
     */
    public static function videoBlock(mixed $block, SignerLocale $locale): mixed
    {
        if ($locale->isReference() || ! is_array($block)) {
            return $block;
        }

        foreach (['title', 'label', 'instructions', 'purpose', 'audience', 'notice', 'consent_label', 'fallback'] as $key) {
            if (is_string($block[$key] ?? null)) {
                $block[$key] = SignerMessageCatalog::translate($block[$key], $locale);
            }
        }

        $block['consent_reviewed'] = self::reviewed($locale);

        return $block;
    }

    public static function reviewed(SignerLocale $locale): bool
    {
        $reviewed = config('assinavelox.multilingual.reviewed_legal_locales', []);

        return $locale->isReference() || (is_array($reviewed) && in_array($locale->value, $reviewed, true));
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    private static function withRoleLabel(array $person, SignerLocale $locale): array
    {
        if (is_string($person['participant_role'] ?? null) && array_key_exists('participant_role_label', $person)) {
            $person['participant_role_label'] = self::ui($locale, 'role.'.$person['participant_role'], $person['participant_role_label']);
        }

        return $person;
    }

    private static function ui(SignerLocale $locale, string $key, mixed $fallback): mixed
    {
        $text = trans('signer_ui.'.$key, [], $locale->value);

        return is_string($text) && $text !== 'signer_ui.'.$key ? $text : $fallback;
    }
}
