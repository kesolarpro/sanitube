<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Identity\Services\ManageUsers;

/**
 * A new account.
 *
 * USR-001. The password rule is **length over composition**, matching
 * `sanitube:user:create`: a twelve-character passphrase is stronger than
 * `P@ss1!` and is one a person will actually remember rather than write on a
 * note beside the screen.
 *
 * Whether the *role* being asked for is allowed is not decided here. That is
 * {@see ManageUsers}' business, because it is the
 * same rule whichever door the request came through — and a rule enforced in a
 * form request is a rule the console command does not get.
 */
final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's role middleware owns this.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ];
    }

    public function role(): UserRole
    {
        return UserRole::from($this->string('role')->toString());
    }
}
