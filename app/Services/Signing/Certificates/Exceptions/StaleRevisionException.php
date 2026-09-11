<?php

namespace App\Services\Signing\Certificates\Exceptions;

use RuntimeException;

/**
 * A revisão sobre a qual a assinatura foi calculada não é mais a mais recente do documento:
 * outro gravador chegou antes. Gravar mesmo assim criaria uma revisão IRMÃ, impossível de
 * fundir (roadmap §2.12). A saída calculada é descartada e a aplicação recomeça sobre a
 * revisão atual.
 */
class StaleRevisionException extends RuntimeException {}
