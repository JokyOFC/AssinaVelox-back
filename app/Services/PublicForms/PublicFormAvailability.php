<?php

namespace App\Services\PublicForms;

use App\Models\PublicForm;

/**
 * Pode receber envios agora? (página pública e confirmação).
 *
 * - `not_found`: token desconhecido, rascunho, revogado ou flag desligada — as quatro
 *   situações recebem a MESMA resposta (404), para a URL não virar oráculo de existência.
 * - `paused` / `expired`: o formulário existe e diz isso com clareza, sem aceitar envio.
 * - `unavailable`: há pendência de configuração (modelo mudou, responsável sem permissão…):
 *   "temporariamente indisponível", sem expor o motivo interno.
 */
final class PublicFormAvailability
{
    public const NOT_FOUND = 'not_found';

    public const PAUSED = 'paused';

    public const EXPIRED = 'expired';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly PublicFormSchema $schema) {}

    /**
     * Formulário pelo token público — sem escopo de organização (rota pública).
     */
    public function resolve(string $token): ?PublicForm
    {
        if (preg_match('/^[A-Za-z0-9]{'.PublicForm::TOKEN_LENGTH.'}$/', $token) !== 1) {
            return null;
        }

        return PublicForm::withoutOrganizationScope()
            ->with(['organization', 'template'])
            ->where('public_token', $token)
            ->first();
    }

    /**
     * null quando o formulário aceita envios.
     */
    public function state(?PublicForm $form): ?string
    {
        if ($form === null || ! PublicFormsFeature::enabled($form->organization)) {
            return self::NOT_FOUND;
        }

        if (in_array($form->status, [PublicFormStatus::Draft, PublicFormStatus::Revoked], true)) {
            return self::NOT_FOUND;
        }

        if ($form->status === PublicFormStatus::Paused) {
            return self::PAUSED;
        }

        if ($form->isExpired()) {
            return self::EXPIRED;
        }

        if ($this->schema->issues($form) !== []) {
            return self::UNAVAILABLE;
        }

        return null;
    }

    public static function message(string $state): string
    {
        return match ($state) {
            self::PAUSED => 'Este formulário está pausado no momento e não está recebendo respostas. Tente mais tarde ou fale com quem enviou o link.',
            self::EXPIRED => 'Este formulário foi encerrado e não recebe mais respostas.',
            self::UNAVAILABLE => 'Este formulário está temporariamente indisponível. Tente mais tarde ou fale com quem enviou o link.',
            default => 'Formulário não encontrado. Confira o endereço com quem enviou o link.',
        };
    }
}
