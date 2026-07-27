<?php

namespace App\Http\Requests\Widget;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'property_id' => ['nullable', 'string', 'max:191'],
            'visitor_id' => ['required', 'string', 'max:191'],
            'visitor_name' => ['nullable', 'string', 'max:191'],
        ];
    }
}
