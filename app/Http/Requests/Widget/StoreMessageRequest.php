<?php

namespace App\Http\Requests\Widget;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
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
            'visitor_name' => ['nullable', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:4000', Rule::requiredIf(fn () => ! $this->hasFile('image'))],
            'image' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
