<?php

namespace App\Services\Anchors;

use RuntimeException;

/**
 * Falha da busca de âncoras ou do OCR. `code` é estável (snake_case, vindo do pdftool ou
 * daqui); a mensagem ao usuário sai de {@see self::messageFor()} — nunca do texto do pdftool,
 * que pode conter caminhos internos.
 */
final class AnchorDetectionException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function make(string $code): self
    {
        $code = preg_match('/^[a-z0-9_]{1,64}$/', $code) === 1 ? $code : 'unknown_error';

        return new self($code, self::messageFor($code));
    }

    /**
     * Mensagem em PT-BR para a interface. Toda falha termina apontando o caminho manual:
     * a detecção nunca é obrigatória para preparar o documento.
     */
    public static function messageFor(?string $code): string
    {
        return match ($code) {
            'pdftool_unavailable' => 'A detecção de campos não está disponível neste servidor agora. Posicione os campos manualmente.',
            'encrypted_pdf' => 'O arquivo está protegido por senha, então não dá para procurar âncoras nele. Posicione os campos manualmente.',
            'invalid_pdf', 'missing_input' => 'Não foi possível ler o texto deste arquivo. Posicione os campos manualmente.',
            'too_many_pages' => 'O arquivo tem páginas demais para a detecção automática. Posicione os campos manualmente.',
            'pdf_too_large' => 'O arquivo é grande demais para a detecção automática. Posicione os campos manualmente.',
            'time_budget_exceeded', 'timeout' => 'A detecção demorou demais e foi interrompida. Posicione os campos manualmente.',
            'ocr_unavailable' => 'OCR indisponível neste servidor. As páginas escaneadas precisam de campos posicionados manualmente.',
            'invalid_spec' => 'Algum texto procurado é inválido. Revise os textos e tente de novo.',
            'nothing_to_search' => 'Nada para procurar: ligue os marcadores ou informe um texto.',
            'document_not_ready' => 'O arquivo ainda está sendo processado. Tente de novo em instantes.',
            'document_replaced' => 'O arquivo foi substituído durante a busca. Rode a detecção de novo.',
            'file_missing' => 'O arquivo do documento não foi encontrado para a detecção.',
            'envelope_locked' => 'O documento saiu do preparo; a detecção foi cancelada.',
            'superseded' => 'Substituída por uma detecção mais recente.',
            'ocr_failed' => 'Não foi possível ler as páginas escaneadas. Posicione os campos manualmente.',
            default => 'Não foi possível concluir a detecção. Posicione os campos manualmente.',
        };
    }
}
