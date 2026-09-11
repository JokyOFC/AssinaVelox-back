<?php

namespace App\Http\Requests\Settings;

use App\Services\Branding\BrandingLimits;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /configuracoes/marca/logo — só confere que chegou UM arquivo dentro do tamanho.
 * Formato, SVG, dimensões e metadados são decididos pelo LogoProcessor a partir do
 * conteúdo (nunca pela extensão ou pelo MIME que o navegador declara).
 */
class BrandingLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = CurrentOrganization::instance()->get();

        return $organization !== null && ($this->user()?->can('updateSettings', $organization) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'max:'.BrandingLimits::logoMaxKb()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => 'Envie um arquivo de imagem.',
            'logo.file' => 'Envie um arquivo de imagem.',
            'logo.uploaded' => 'O envio do arquivo falhou. Tente de novo.',
            'logo.max' => sprintf('O logo pode ter no máximo %s KB.', number_format(BrandingLimits::logoMaxKb(), 0, ',', '.')),
        ];
    }
}
