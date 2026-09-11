<?php

namespace App\Integrations\Email;

use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\SenderDomainVerifier;

/**
 * Verificação de domínio pela API do serviço de e-mail do proprietário — DESABILITADA.
 *
 * O envio por SMTP da Fase 1 continua como está; o que falta é a API (regra fixa 1 da
 * viabilidade): sem documentação, nenhuma chamada é escrita e `isConfigured()` é sempre false.
 */
final class HttpSenderDomainVerifier implements SenderDomainVerifier
{
    public const NAME = 'email_api_servico_proprio';

    public function name(): string
    {
        return self::NAME;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function missingRequirements(): array
    {
        $missing = [
            'Documentação da API do serviço de e-mail do proprietário para cadastrar um domínio de envio e consultar a sua situação.',
            'Registros DNS exigidos pelo serviço (DKIM, SPF, Return-Path, verificação de posse) e o formato em que a API os devolve.',
            'Esquema de autenticação da API e credenciais de homologação.',
            'Códigos de situação do domínio e de erro, e limites de chamadas.',
            'Confirmação de que o envio SMTP atual aceita remetente do domínio verificado (alinhamento DKIM/SPF).',
        ];

        $service = (array) config('services.assinavelox_email_api', []);

        foreach (['base_url' => 'BASE_URL', 'credentials' => 'CREDENTIALS'] as $key => $suffix) {
            if (! is_string($service[$key] ?? null) || $service[$key] === '') {
                $missing[] = sprintf('Variável ASSINAVELOX_EMAIL_API_%s não definida (config services.assinavelox_email_api.%s).', $suffix, $key);
            }
        }

        $missing[] = 'Mesmo com as variáveis definidas, o adaptador continua desabilitado até ser implementado contra a documentação oficial.';

        return $missing;
    }

    public function register(string $domain, string $verificationToken, ?string $correlationId = null): array
    {
        throw new ProviderDisabledException(self::NAME, $this->missingRequirements());
    }

    public function check(string $domain, ?string $providerDomainId, ?string $correlationId = null): array
    {
        throw new ProviderDisabledException(self::NAME, $this->missingRequirements());
    }
}
