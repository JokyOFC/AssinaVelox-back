<?php

namespace App\Services\Pdf\Exceptions;

/**
 * Exit code 2: erro de uso — argumentos inválidos, plano inválido, variável de
 * ambiente da passphrase ausente (missing_passphrase). Indica bug ou
 * configuração errada do lado do Laravel, não um documento ruim.
 */
class PdfToolUsageException extends PdfToolException {}
