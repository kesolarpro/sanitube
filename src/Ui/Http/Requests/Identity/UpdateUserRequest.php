<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Identity\Enums\UserRole;

/**
 * A change to somebody's role, or to whether they may sign in.
 *
 * USR-001. One field or the other, never both in one request: "promote them
 * and deactivate them" is two decisions, and an audit line describing it as
 * one act would be a line nobody can act on later.
 */
final class UpdateUserRequest extends FormRequest
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
            'role' => ['nullable', 'required_without:is_active', Rule::enum(UserRole::class)],
            'is_active' => ['nullable', 'required_without:role', 'boolean'],
        ];
    }

    public function newRole(): ?UserRole
    {
        $role = $this->validated('role');

        return is_string($role) && $role !== '' ? UserRole::from($role) : null;
    }

    public function activation(): ?bool
    {
        $active = $this->validated('is_active');

        return $active === null ? null : (bool) $active;
    }
}
