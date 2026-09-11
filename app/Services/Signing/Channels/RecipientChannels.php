<?php

namespace App\Services\Signing\Channels;

use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Models\AuthChallenge;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use App\Rules\PhoneE164;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Signing\SignerSessions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Canal, método de autenticação, telefone e PIN de cada linha do sync de destinatários
 * (PUT envelopes.recipients.sync). Chamado por App\Services\Envelopes\RecipientSync.
 *
 * Regras (docs/fase-2/canais-e-pin.md §3):
 *  - campo ausente mantém o valor gravado; linha nova nasce `email_otp`, sem canal extra;
 *  - escolher SMS/WhatsApp (como método ou como canal) exige o canal DISPONÍVEL agora:
 *    flag `sms_whatsapp` + provedor pronto. Senão, erro com o motivo;
 *  - um valor já gravado é preservado mesmo que a flag seja desligada depois;
 *  - SMS/WhatsApp exigem `phone` válido, normalizado em E.164 (libphonenumber, região BR);
 *  - com a flag desligada, `phone` enviado é ignorado (comportamento da Fase 1);
 *  - `pin` só com a flag `pin_auth`; 4–8 dígitos, não previsível; nunca volta em props.
 */
final class RecipientChannels
{
    public function __construct(
        private readonly ChannelAvailability $availability,
        private readonly SenderPins $pins,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $incoming  linhas na ordem final, por posição
     * @return array<int, array{phone: string|null, delivery_channel: string|null, auth_method: AuthMethod, pin: string|null, remove_pin: bool}>
     *
     * @throws ValidationException
     */
    public function resolve(Envelope $envelope, array $incoming): array
    {
        $organization = $envelope->organization;
        $channelsEnabled = ChannelFeatures::smsWhatsapp($organization);
        $pinEnabled = ChannelFeatures::pinAuth($organization);

        /** @var array<string, Recipient> $existing */
        $existing = $envelope->recipients()->get()->keyBy('ulid')->all();

        $resolved = [];
        $errors = [];

        foreach ($incoming as $position => $row) {
            $ulid = isset($row['id']) && is_string($row['id']) ? $row['id'] : null;
            $current = $ulid !== null ? ($existing[$ulid] ?? null) : null;
            $prefix = "recipients.{$position}";

            // Método de autenticação.
            $method = $current !== null ? $current->auth_method : AuthMethod::EmailOtp;
            $rawMethod = $row['auth_method'] ?? null;

            if (is_string($rawMethod) && $rawMethod !== '') {
                $chosen = AuthMethod::tryFrom($rawMethod);

                if ($chosen === null) {
                    $errors["{$prefix}.auth_method"] = 'Método de autenticação inválido.';

                    continue;
                }

                if ($chosen !== $method && $chosen->requiresPhone()) {
                    $describe = $this->availability->describe($chosen->channel(), $organization);

                    if (! $describe['available']) {
                        $errors["{$prefix}.auth_method"] = (string) $describe['reason'];

                        continue;
                    }
                }

                $method = $chosen;
            }

            // Canal de entrega do convite (nulo = só e-mail).
            $currentChannel = $current?->getAttribute('delivery_channel');
            $delivery = is_string($currentChannel) && $currentChannel !== '' ? $currentChannel : null;
            $rawChannel = $row['channel'] ?? null;

            if (is_string($rawChannel) && $rawChannel !== '') {
                $channel = DeliveryChannel::tryFrom($rawChannel);

                if ($channel === null) {
                    $errors["{$prefix}.channel"] = 'Canal de envio inválido.';

                    continue;
                }

                $value = $channel === DeliveryChannel::Email ? null : $channel->value;

                if ($value !== null && $value !== $delivery) {
                    $describe = $this->availability->describe($channel, $organization);

                    if (! $describe['available']) {
                        $errors["{$prefix}.channel"] = (string) $describe['reason'];

                        continue;
                    }
                }

                $delivery = $value;
            }

            // Telefone.
            $needsPhone = $method->requiresPhone() || $delivery !== null;
            $phone = $current?->phone;
            $rawPhone = array_key_exists('phone', $row) ? trim((string) $row['phone']) : null;

            if ($rawPhone !== null && ($channelsEnabled || $needsPhone)) {
                if ($rawPhone === '') {
                    $phone = null;
                } else {
                    $normalized = PhoneE164::normalize($rawPhone);

                    if ($normalized === null) {
                        $errors["{$prefix}.phone"] = 'Informe um celular válido com DDD, por exemplo +55 11 91234-5678.';

                        continue;
                    }

                    $phone = $normalized;
                }
            }

            if ($needsPhone) {
                $normalized = $phone !== null ? PhoneE164::normalize($phone) : null;

                if ($normalized === null) {
                    $errors["{$prefix}.phone"] = 'Informe o celular (com DDD) para enviar por SMS ou WhatsApp.';

                    continue;
                }

                $phone = $normalized;
            }

            // PIN do remetente.
            $pin = isset($row['pin']) && is_scalar($row['pin']) ? trim((string) $row['pin']) : '';
            $removePin = filter_var($row['remove_pin'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($pin !== '') {
                if (! $pinEnabled) {
                    $errors["{$prefix}.pin"] = 'O PIN do remetente não está habilitado para esta organização.';

                    continue;
                }

                if (! SenderPins::isWellFormed($pin)) {
                    $errors["{$prefix}.pin"] = sprintf('O PIN deve ter de %d a %d dígitos.', SenderPins::minLength(), SenderPins::maxLength());

                    continue;
                }

                if (SenderPins::isWeak($pin)) {
                    $errors["{$prefix}.pin"] = 'Escolha um PIN menos previsível (evite sequências como 1234 e dígitos repetidos como 0000).';

                    continue;
                }
            }

            $resolved[$position] = [
                'phone' => $phone,
                'delivery_channel' => $delivery,
                'auth_method' => $method,
                'pin' => $pin !== '' ? $pin : null,
                'remove_pin' => $removePin && $pin === '',
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * Grava/remove os PINs depois do sync (os destinatários novos já têm ULID aqui).
     *
     * @param  array<int, Recipient>  $saved  por posição
     * @param  array<int, array{phone: string|null, delivery_channel: string|null, auth_method: AuthMethod, pin: string|null, remove_pin: bool}>  $resolved
     */
    public function applyPins(Envelope $envelope, array $saved, array $resolved): void
    {
        $user = Auth::user();

        foreach ($saved as $position => $recipient) {
            $data = $resolved[$position] ?? null;

            if ($data === null) {
                continue;
            }

            if ($data['pin'] !== null) {
                $this->pins->set($recipient, $data['pin'], $user instanceof User ? $user : null);

                EnvelopeAudit::record($envelope, AuditEventType::RecipientPinUpdated, [
                    'recipient' => $recipient->ulid,
                    'action' => 'set',
                ], $recipient);

                continue;
            }

            if ($data['remove_pin'] && $this->pins->remove($recipient)) {
                EnvelopeAudit::record($envelope, AuditEventType::RecipientPinUpdated, [
                    'recipient' => $recipient->ulid,
                    'action' => 'removed',
                ], $recipient);
            }
        }
    }

    /**
     * Edição DEPOIS DO ENVIO (PATCH envelopes.recipients.update): celular, método do código e
     * PIN novo de UM participante pendente. Mesmas regras do wizard ({@see self::resolve()}):
     * SMS/WhatsApp só com o canal disponível agora, celular em E.164, PIN bem formado e não
     * previsível. Campo ausente mantém o valor gravado. Sem efeito colateral: só valida.
     *
     * Os erros voltam com a chave do campo (`phone`, `auth_method`, `pin`), como no diálogo.
     *
     * @param  array{phone?: string|null, auth_method?: string|null, pin?: string|null}  $input
     * @return array{phone: string|null, delivery_channel: string|null, auth_method: AuthMethod, pin: string|null, remove_pin: bool}
     *
     * @throws ValidationException
     */
    public function resolveSent(Envelope $envelope, Recipient $recipient, array $input): array
    {
        $row = ['id' => $recipient->ulid];

        foreach (['phone', 'auth_method', 'pin'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $row[$key] = $input[$key];
            }
        }

        try {
            return $this->resolve($envelope, [0 => $row])[0];
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors[(string) preg_replace('/^recipients\.0\./', '', (string) $key)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Grava o resultado de {@see self::resolveSent()}.
     *
     * Trocar o celular ou o método do código encerra as sessões e os códigos ainda vivos do
     * participante: quem confirmou um código no celular errado não pode continuar. O PIN novo
     * zera tentativas e bloqueios ({@see SenderPins::set()}). Nada de telefone ou PIN na trilha.
     *
     * @param  array{phone: string|null, delivery_channel: string|null, auth_method: AuthMethod, pin: string|null, remove_pin: bool}  $data
     * @return array{channel_changed: bool, pin_set: bool}
     */
    public function applySent(Envelope $envelope, Recipient $recipient, array $data): array
    {
        $phoneChanged = $data['phone'] !== $recipient->phone;
        $methodChanged = $data['auth_method'] !== $recipient->auth_method;
        $channelChanged = $phoneChanged || $methodChanged
            || $data['delivery_channel'] !== $recipient->getAttribute('delivery_channel');

        if ($channelChanged) {
            $recipient->forceFill(self::attributes($data))->save();

            $revoked = app(SignerSessions::class)->revokeAllFor($recipient);

            AuthChallenge::withoutOrganizationScope()
                ->where('recipient_id', $recipient->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => Carbon::now()]);

            EnvelopeAudit::record($envelope, AuditEventType::RecipientsUpdated, [
                'recipient' => $recipient->ulid,
                'phone_changed' => $phoneChanged,
                'auth_method_changed' => $methodChanged,
                'auth_method' => $data['auth_method']->value,
                'sessions_revoked' => $revoked,
            ], $recipient);
        }

        $pinSet = false;

        if ($data['pin'] !== null) {
            $wasBlocked = (bool) ($this->pins->recordFor($recipient)?->isBlocked() ?? false);
            $user = Auth::user();

            $this->pins->set($recipient, $data['pin'], $user instanceof User ? $user : null);
            $pinSet = true;

            EnvelopeAudit::record($envelope, AuditEventType::RecipientPinUpdated, [
                'recipient' => $recipient->ulid,
                'action' => 'set',
                'after_send' => true,
                'was_blocked' => $wasBlocked,
            ], $recipient);
        }

        return ['channel_changed' => $channelChanged, 'pin_set' => $pinSet];
    }

    /**
     * Estado do PIN para o cartão do participante no detalhe (sem o PIN, claro).
     *
     * @return 'active'|'locked'|'blocked'|null
     */
    public function pinState(Recipient $recipient): ?string
    {
        $record = $this->pins->recordFor($recipient);

        return match (true) {
            $record === null => null,
            $record->isBlocked() => 'blocked',
            $record->isLocked() => 'locked',
            default => 'active',
        };
    }

    /**
     * Atributos de canal a gravar na linha de `recipients`.
     *
     * @param  array{phone: string|null, delivery_channel: string|null, auth_method: AuthMethod, pin: string|null, remove_pin: bool}  $data
     * @return array{phone: string|null, delivery_channel: string|null, auth_method: AuthMethod}
     */
    public static function attributes(array $data): array
    {
        return [
            'phone' => $data['phone'],
            'delivery_channel' => $data['delivery_channel'],
            'auth_method' => $data['auth_method'],
        ];
    }

    /**
     * Campos de canal de um destinatário para o wizard (`RecipientWizardResource`, fora
     * desta área). O PIN nunca volta: só `has_pin`.
     *
     * @return array{phone: string|null, phone_masked: string, channel: string, auth_method: string, auth_method_label: string, has_pin: bool}
     */
    public function wizardFields(Recipient $recipient): array
    {
        $channel = ChannelInvitations::channelOf($recipient);

        return [
            'phone' => $recipient->phone,
            'phone_masked' => PhoneE164::mask($recipient->phone),
            'channel' => ($channel ?? DeliveryChannel::Email)->value,
            'auth_method' => $recipient->auth_method->value,
            'auth_method_label' => $recipient->auth_method->label(),
            'has_pin' => $this->pins->requiredFor($recipient),
        ];
    }
}
