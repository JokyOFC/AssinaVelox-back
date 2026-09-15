<?php

namespace App\Http\Controllers\Embed\Requests;

use App\Http\Requests\Sign\StoreAcceptanceRequest;

/**
 * Aceite pelo widget embutido (docs/fase-3/widget-embutido.md §3.6): a MESMA forma do aceite
 * da página pública (`StoreAcceptanceRequest`) mais o registro de que o clique final passou
 * pela confirmação visual com o widget visível.
 *
 * `interaction` não é fronteira de segurança — quem tem o token de execução poderia forjá-lo —;
 * a proteção contra clickjacking é do navegador (`frame-ancestors`, IntersectionObserver v2,
 * `visibilityState`) e fica no widget. Exigir o campo aqui garante que o widget nunca registra
 * um aceite por um caminho que pulou a confirmação.
 */
class EmbedAcceptanceRequest extends StoreAcceptanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'interaction' => ['required', 'array'],
            'interaction.confirmed' => ['required', 'accepted'],
            'interaction.visible' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $confirm = 'Confirme o aceite na janela de confirmação, com o documento visível na tela.';

        return parent::messages() + [
            'interaction.required' => $confirm,
            'interaction.array' => $confirm,
            'interaction.confirmed.required' => $confirm,
            'interaction.confirmed.accepted' => $confirm,
            'interaction.visible.required' => $confirm,
            'interaction.visible.accepted' => $confirm,
        ];
    }
}
