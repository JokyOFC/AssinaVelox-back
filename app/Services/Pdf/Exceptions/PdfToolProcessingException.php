<?php

namespace App\Services\Pdf\Exceptions;

/**
 * Exit code 3 (internal_error, signing_failed, pdf_write_failed...) e falhas de
 * infraestrutura detectadas pelo cliente: timeout, processo não iniciou,
 * stdout sem JSON válido (invalid_output), exit code desconhecido.
 */
class PdfToolProcessingException extends PdfToolException {}
