<?php

declare(strict_types=1);

namespace SaniTube\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // No complexity rule on *sign-in*. The password either matches the
            // stored hash or it does not, and rejecting a login because the
            // stored password predates a policy would lock a real user out of
            // their own account.
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
