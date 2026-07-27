<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CallSignalRequest extends FormRequest
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
            'type' => ['required', Rule::in(['invite', 'accept', 'reject', 'end'])],
            'mode' => ['required_if:type,invite', Rule::in(['video', 'audio', 'screen'])],
            'peer_id' => ['required_if:type,invite', 'string', 'max:191'],
        ];
    }
}
