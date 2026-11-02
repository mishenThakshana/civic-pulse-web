<?php

namespace App\Http\Requests\Api\Integrations;

use App\Enums\ReportStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportUpdateRequest extends FormRequest
{
    /**
     * Access is decided by the department.key:write middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ReportStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'file', 'image', 'max:6144'],
        ];
    }
}
