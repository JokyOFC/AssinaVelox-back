<?php

namespace App\Integrations\Dto;

/**
 * E-mail de saída independente do provedor. Sem tokens/OTP em `tags` ou
 * `headers` (eles vão apenas no corpo, que não é registrado em log).
 */
final readonly class OutboundEmail
{
    /**
     * @param  array<string, string>  $headers  cabeçalhos adicionais (ex.: List-Unsubscribe)
     * @param  list<string>  $tags  categorias para o provedor (ex.: 'invitation', 'otp')
     * @param  list<EmailAttachment>  $attachments
     */
    public function __construct(
        public string $toAddress,
        public ?string $toName,
        public string $subject,
        public string $htmlBody,
        public ?string $textBody = null,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $replyToAddress = null,
        public array $headers = [],
        public array $tags = [],
        public array $attachments = [],
        public ?string $correlationId = null,
    ) {}
}
