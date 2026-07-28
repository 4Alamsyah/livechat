<?php

namespace App\Http\Requests\Agent;

use App\Enums\AnnouncementLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'level' => ['nullable', Rule::enum(AnnouncementLevel::class)],
            'property_ids' => ['nullable', 'array'],
            'property_ids.*' => ['string', 'max:120'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ];
    }
}
