<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()->min(8)->mixedCase()->numbers()],
            // Self-registration may only ever produce a customer or a seller.
            // Admin accounts are provisioned out of band -- accepting `admin`
            // here would be a straightforward privilege-escalation hole.
            'role' => ['sometimes', Rule::in([UserRole::Customer->value, UserRole::Seller->value])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.in' => 'The selected role must be either customer or seller.',
        ];
    }
}
