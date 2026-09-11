<?php

namespace App\Http\Requests\Batch;

use App\Http\Requests\InPerson\ParticipantAcceptanceRequest;

/**
 * Autorização de UM item do lote (`sign.batch.items.authorize`). Mesma forma do aceite
 * individual; o item é o do caminho — qualquer lista de itens no corpo é ignorada.
 */
class AuthorizeBatchItemRequest extends ParticipantAcceptanceRequest {}
