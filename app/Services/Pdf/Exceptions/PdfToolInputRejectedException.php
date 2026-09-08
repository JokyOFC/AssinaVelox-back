<?php

namespace App\Services\Pdf\Exceptions;

/**
 * Exit code 4: entrada rejeitada — PDF corrompido (invalid_pdf), criptografado
 * (encrypted_pdf), imagem inválida/não suportada, arquivo ausente, senha do
 * PKCS#12 errada (pfx_load_failed). O documento é o problema; não repetir.
 */
class PdfToolInputRejectedException extends PdfToolException
{
    public function isEncrypted(): bool
    {
        return $this->errorCode === 'encrypted_pdf';
    }

    public function isInvalidPdf(): bool
    {
        return $this->errorCode === 'invalid_pdf';
    }
}
