<?php

namespace App\Integrations\Pdf;

/**
 * Mensagens PT-BR estáveis por `reason_code`, para documents.failure_message.
 * Nunca incluem caminhos locais nem detalhes internos.
 */
final class ConversionMessages
{
    public static function for(string $code, ?string $fallback = null): string
    {
        return match ($code) {
            'encrypted_pdf' => 'O PDF está protegido por senha ou criptografia. Remova a proteção e envie novamente.',
            'has_signatures' => 'O PDF já contém assinaturas digitais; qualquer alteração as invalidaria. Envie a versão sem assinaturas.',
            'invalid_pdf' => 'O arquivo não é um PDF válido ou está corrompido.',
            'not_openable' => 'O PDF não pôde ser aberto.',
            'missing_input' => 'O arquivo enviado não foi encontrado para processamento.',
            'unsupported_image' => 'Formato de imagem não suportado. Use PNG, JPEG ou WEBP.',
            'invalid_image' => 'A imagem está corrompida ou não pôde ser lida.',
            'image_too_large' => 'A imagem excede o limite de 40 megapixels.',
            'timeout' => 'A conversão excedeu o tempo limite.',
            'libreoffice_failed' => 'O conversor de documentos não conseguiu processar o arquivo.',
            'no_output' => 'O conversor de documentos não gerou o PDF.',
            'invalid_output_pdf' => 'O PDF gerado pelo conversor é inválido.',
            default => $fallback ?? sprintf('O documento não pôde ser processado (%s).', $code),
        };
    }
}
