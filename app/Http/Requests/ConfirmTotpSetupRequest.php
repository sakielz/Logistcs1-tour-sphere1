<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmTotpSetupRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'secret' => ['required', 'string', 'size:16'],
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
            'recovery_codes' => ['required', 'array', 'size:8'],
            'recovery_codes.*' => ['required', 'string', 'regex:/^TRVL-[0-9A-F]{4}-[0-9A-F]{2}$/'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'The 6-digit Google Authenticator code is required.',
            'code.size' => 'The authenticator code must be exactly 6 digits.',
            'code.regex' => 'The authenticator code must contain numbers only.',
            'secret.required' => 'The TOTP provisioning secret is missing or invalid.',
            'recovery_codes.size' => 'Exactly 8 emergency recovery codes are required.',
        ];
    }
}
