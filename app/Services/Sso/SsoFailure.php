<?php

namespace App\Services\Sso;

use RuntimeException;

/**
 * Falha do login corporativo. `reason` é um código estável (vai para a trilha e para os
 * testes); a mensagem ao usuário é genérica de propósito — nunca repete claim, token, trecho
 * do XML ou resposta do provedor. Nada de segredo em `getMessage()`.
 */
final class SsoFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        private readonly ?string $userMessage = null,
        /** Campo do formulário a que o erro se refere (telas de configuração). */
        public readonly ?string $field = null,
    ) {
        parent::__construct('Falha no login corporativo: '.$reason);
    }

    public function forField(string $field): self
    {
        return new self($this->reason, $this->userMessage, $field);
    }

    /** Motivos em que a mensagem específica ajuda quem está entrando (e não expõe configuração). */
    private const PUBLIC_REASONS = [
        'no_connection', 'connection_unavailable', 'domain_not_allowed', 'email_not_verified', 'email_missing',
        'no_account', 'not_a_member', 'membership_suspended', 'account_blocked', 'local_account_unverified',
        'identity_conflict', 'no_seats', 'provider_unreachable', 'flow_expired', 'state_invalid',
        'saml_request_unknown', 'saml_browser_mismatch',
    ];

    /**
     * Mensagem para a tela de LOGIN (público): detalhes de configuração do provedor (endereço
     * bloqueado, emissor divergente, certificado) ficam só na trilha e na tela de configuração.
     */
    public function publicMessage(): string
    {
        if (in_array($this->reason, self::PUBLIC_REASONS, true)) {
            return $this->userMessage();
        }

        return str_starts_with($this->reason, 'provider_error')
            ? (new self('provider_error'))->userMessage()
            : (new self('generic'))->userMessage();
    }

    public function userMessage(): string
    {
        return $this->userMessage ?? match ($this->reason) {
            'no_connection' => 'Não há login corporativo disponível para este domínio. Entre com e-mail e senha.',
            'connection_unavailable' => 'O login corporativo desta organização ainda não está disponível.',
            'domain_not_allowed' => 'O e-mail informado pelo provedor de identidade não pertence a um domínio verificado desta organização.',
            'email_not_verified' => 'O provedor de identidade não confirmou o seu e-mail. Fale com o administrador da sua empresa.',
            'no_account' => 'Você ainda não tem acesso a esta organização. Peça um convite ao administrador.',
            'not_a_member' => 'Você ainda não participa desta organização. Peça um convite ao administrador.',
            'membership_suspended' => 'Seu acesso a esta organização está suspenso.',
            'account_blocked' => 'Esta conta está bloqueada. Fale com o suporte da AssinaVelox.',
            'local_account_unverified' => 'Já existe uma conta com este e-mail que ainda não foi confirmada. Confirme o e-mail da conta antes de entrar pelo login corporativo.',
            'identity_conflict' => 'Esta conta já está ligada a outra identidade no provedor. Fale com o administrador da sua empresa.',
            'no_seats' => 'Não há assentos disponíveis no plano desta organização. Fale com o administrador.',
            'provider_unreachable' => 'Não foi possível falar com o provedor de identidade agora. Tente de novo em instantes.',
            'flow_expired', 'state_invalid', 'saml_request_unknown', 'saml_browser_mismatch' => 'O login corporativo expirou ou foi iniciado em outro navegador. Comece de novo.',
            'provider_error' => 'O provedor de identidade não concluiu o login.',
            default => 'Não foi possível entrar pelo login corporativo. Comece de novo ou fale com o administrador da sua empresa.',
        };
    }
}
