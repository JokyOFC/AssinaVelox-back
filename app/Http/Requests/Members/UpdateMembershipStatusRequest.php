<?php

namespace App\Http\Requests\Members;

use App\Enums\MembershipStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /usuarios/{membership}/status: `status ∈ active | suspended`.
 */
class UpdateMembershipStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', $this->route('membership')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(MembershipStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'status'];
    }

    public function status(): MembershipStatus
    {
        return MembershipStatus::from($this->validated('status'));
    }
}
