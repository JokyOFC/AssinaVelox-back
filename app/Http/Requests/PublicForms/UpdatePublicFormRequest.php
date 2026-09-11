<?php

namespace App\Http\Requests\PublicForms;

use App\Models\PublicForm;
use App\Services\PublicForms\PublicFormDestination;
use App\Services\PublicForms\PublicFormSchema;
use App\Services\PublicForms\SubmissionPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Forma e tamanho da configuração. As regras que dependem do modelo (variável existe, papel
 * é de signatário, valor fixo válido pelo tipo, envio automático possível) ficam em
 * {@see PublicFormSchema::build()}.
 */
class UpdatePublicFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $form = $this->route('publicForm');

        return $form instanceof PublicForm && Gate::allows('update', $form);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'envelope_title' => ['nullable', 'string', 'max:120'],
            'destination' => ['required', Rule::enum(PublicFormDestination::class)],
            'submissions_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            'submissions_period' => ['required', Rule::enum(SubmissionPeriod::class)],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'public_variables' => ['nullable', 'array', 'max:200'],
            'public_variables.*' => ['string', 'max:64'],
            'fixed_values' => ['nullable', 'array', 'max:200'],
            'fixed_values.*' => ['nullable', 'string', 'max:5000'],
            'filler_role' => ['required', 'string', 'size:26'],
            'fixed_participants' => ['nullable', 'array', 'max:50'],
            'fixed_participants.*' => ['array'],
            'fixed_participants.*.name' => ['nullable', 'string', 'max:120'],
            'fixed_participants.*.email' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'instructions' => 'instruções',
            'envelope_title' => 'título do documento',
            'destination' => 'destino',
            'submissions_limit' => 'limite de respostas',
            'submissions_period' => 'período do limite',
            'expires_at' => 'data de encerramento',
            'filler_role' => 'papel de quem preenche',
        ];
    }
}
