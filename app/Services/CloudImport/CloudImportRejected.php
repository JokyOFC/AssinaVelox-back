<?php

namespace App\Services\CloudImport;

use App\Services\CloudImport\Http\ConnectorFailure;
use App\Services\Documents\Exceptions\UploadRejectedException;
use RuntimeException;

/**
 * Importação da nuvem recusada. `errorCode` é estável (gravado em `cloud_imports` e na trilha);
 * `userMessage()` é o texto da tela, em PT-BR. Nenhum dos dois carrega token, link ou detalhe
 * de rede (a mensagem para "não resolve" e "resolve para endereço interno" é a mesma).
 */
final class CloudImportRejected extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        private readonly string $userMessage,
    ) {
        parent::__construct('Importação da nuvem recusada: '.$errorCode);
    }

    public static function make(string $code, string $message): self
    {
        return new self($code, $message);
    }

    public static function fromUpload(UploadRejectedException $exception): self
    {
        return new self('upload_'.$exception->errorCode, $exception->getMessage());
    }

    public static function fromConnector(ConnectorFailure $failure, int $maxBytes): self
    {
        $megabytes = max(1, (int) floor($maxBytes / 1024 / 1024));

        return match (true) {
            $failure->errorCode === ConnectorFailure::BLOCKED_URL => new self(
                'blocked_url',
                'O endereço de download recebido não é um endereço permitido do provedor. Escolha o arquivo de novo.',
            ),
            $failure->errorCode === ConnectorFailure::TOO_LARGE => new self(
                'file_too_large',
                sprintf('O arquivo excede o limite de %d MB.', $megabytes),
            ),
            $failure->errorCode === ConnectorFailure::EMPTY => new self('empty_file', 'O arquivo recebido do provedor está vazio.'),
            in_array($failure->httpStatus, [401, 403], true) => new self(
                'provider_denied',
                'O provedor recusou o acesso ao arquivo. Autorize de novo e escolha o arquivo outra vez.',
            ),
            $failure->httpStatus === 404 => new self('provider_not_found', 'O arquivo não foi encontrado no provedor.'),
            $failure->isTimeout() => new self(
                'provider_timeout',
                'O provedor demorou demais para responder. Tente de novo em instantes.',
            ),
            default => new self(
                'provider_unavailable',
                'Não foi possível baixar o arquivo do provedor agora. Tente de novo em instantes.',
            ),
        };
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Motivo em PT-BR para "Importações deste documento", a partir do código gravado (a mensagem
     * original não é guardada). Nunca o código cru na interface.
     */
    public static function describe(?string $code): string
    {
        return match (true) {
            $code === 'file_too_large' => 'o arquivo excede o limite de tamanho',
            $code === 'empty_file' => 'o arquivo recebido do provedor está vazio',
            $code === 'blocked_url' => 'o endereço de download não é um endereço permitido do provedor',
            $code === 'provider_denied', $code === 'authorization_required' => 'o provedor recusou o acesso ao arquivo; autorize de novo',
            $code === 'provider_not_found' => 'o arquivo não foi encontrado no provedor',
            $code === 'provider_timeout' => 'o provedor demorou demais para responder',
            $code === 'provider_unavailable', $code === 'provider_error' => 'não foi possível baixar o arquivo do provedor',
            $code === 'provider_not_configured' => 'o provedor ainda não está disponível',
            $code === 'provider_mismatch', $code === 'invalid_selection' => 'a seleção do arquivo não é válida',
            $code === 'envelope_not_editable' => 'o documento não aceita mais arquivos',
            is_string($code) && str_starts_with($code, 'upload_') => 'o arquivo não passou nas verificações de envio',
            default => 'motivo não informado',
        };
    }
}
