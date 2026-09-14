<?php

namespace App\Http\Requests\Sign;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST assinar/{token}/externa/simulador/assinar` (Fase 3 §3.4, só teste/local): o SIMULADOR
 * assina o digest da própria reserva no servidor. Só o identificador da reserva é aceito — o
 * digest nunca vem do cliente.
 */
class ExternalSimulatorSignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pending_id' => ['required', 'string', 'size:26', 'alpha_num'],
        ];
    }
}
