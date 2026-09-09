<?php

namespace App\Services\Envelopes\Finalization\Exceptions;

use RuntimeException;

/**
 * Falha da finalização. Sempre carrega um `code` estável (snake_case) que vai para a
 * trilha (`envelope.finalization_failed`) e para o log — nunca segredos, nunca o conteúdo
 * do documento e nunca a senha do certificado.
 *
 * Uma exceção daqui **não conclui o envelope**: ele permanece em `finalizing` e o job
 * tenta de novo. Falhar em voz alta é o comportamento correto — a alternativa seria
 * concluir sem o arquivo final ou, pior, sem a assinatura prometida.
 */
class FinalizationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missingSentVersion(): self
    {
        return new self(
            'missing_sent_version',
            'O envelope não tem versão congelada no envio; não há o que consolidar.',
        );
    }

    public static function missingVerificationCode(): self
    {
        return new self(
            'missing_verification_code',
            'O envelope não tem código de verificação; ele é gerado no envio.',
        );
    }

    public static function missingDocument(): self
    {
        return new self('missing_document', 'O envelope não tem documento associado.');
    }

    public static function sourceUnavailable(): self
    {
        return new self(
            'source_unavailable',
            'Os bytes da versão enviada não foram encontrados no disco de documentos.',
        );
    }

    public static function signingFailed(\Throwable $previous): self
    {
        return new self(
            'signing_failed',
            'A assinatura criptográfica da operadora falhou; o envelope não foi concluído.',
            ['exception' => $previous::class],
            $previous,
        );
    }

    public static function signatureNotVerifiable(string $reason): self
    {
        return new self(
            'signature_not_verifiable',
            'O arquivo assinado não pôde ser validado; o envelope não foi concluído.',
            ['reason' => $reason],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function writeFailed(string $step, array $context = []): self
    {
        return new self(
            'write_failed',
            sprintf('Não foi possível gravar o artefato da etapa "%s" da finalização.', $step),
            $context + ['step' => $step],
        );
    }
}
