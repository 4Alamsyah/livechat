<?php

namespace App\Http\Requests\Agent;

use App\Enums\WidgetPosition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWidgetSettingsRequest extends FormRequest
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
            'property_id' => ['required', 'string', 'max:191'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['nullable', Rule::enum(WidgetPosition::class)],
            'brand_name' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'welcome_message' => ['nullable', 'string', 'max:1000'],
            'require_name' => ['nullable', 'boolean'],
            'collect_email' => ['nullable', 'boolean'],
            'require_email' => ['nullable', 'boolean'],
            'collect_topic' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'business_hours_enabled' => ['nullable', 'boolean'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.*.enabled' => ['boolean'],
            'business_hours.*.start' => ['nullable', 'date_format:H:i'],
            'business_hours.*.end' => ['nullable', 'date_format:H:i'],
            'offline_message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
