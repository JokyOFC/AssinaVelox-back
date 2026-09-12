<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paginação por cursor da API v1: `per_page` (1–100, padrão 25) e `cursor` (opaco, vindo de
 * `meta.next_cursor`/`links.next` da página anterior). A autorização fica nas abilities da
 * rota e nas Policies.
 */
class CursorPageRequest extends FormRequest
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::maxPerPage()],
            'cursor' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function perPage(): int
    {
        $value = $this->validated('per_page');

        return is_numeric($value)
            ? (int) $value
            : max(1, min(self::maxPerPage(), (int) config('assinavelox.api.page_size.default', 25)));
    }

    public static function maxPerPage(): int
    {
        return max(1, (int) config('assinavelox.api.page_size.max', 100));
    }
}
