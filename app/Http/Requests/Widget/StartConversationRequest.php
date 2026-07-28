<?php

namespace App\Http\Requests\Widget;

use App\Models\WidgetSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartConversationRequest extends FormRequest
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
        $settings = WidgetSetting::forProperty($this->input('property_id'));

        return [
            'property_id' => ['nullable', 'string', 'max:191'],
            'visitor_id' => ['required', 'string', 'max:191'],
            'visitor_name' => [Rule::requiredIf($settings->require_name), 'nullable', 'string', 'max:191'],
            'visitor_email' => [Rule::requiredIf($settings->require_email), 'nullable', 'email', 'max:191'],
            'topic' => ['nullable', 'string', 'max:191'],
        ];
    }
}
