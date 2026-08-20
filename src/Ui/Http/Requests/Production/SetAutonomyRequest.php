<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Production\Enums\AutonomyMode;

/**
 * Granting or withdrawing the platform's licence to act alone.
 *
 * The mode is validated against the enum here rather than trusted to the
 * service, because a 422 naming the field is what a form can show and an
 * exception from three layers down is not. The service validates it again —
 * this is a screen's convenience, never the guard.
 *
 * `AutonomousRelease` is a member of the enum and is locked in the domain. It
 * is deliberately still offered to validation: refusing it here would produce
 * "that is not a mode", and refusing it in the service produces "that mode is
 * not available yet", which is the true answer.
 */
final class SetAutonomyRequest extends FormRequest
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
            'autonomy_mode' => ['required', 'string', Rule::in(array_map(
                static fn (AutonomyMode $case): string => $case->value,
                AutonomyMode::cases(),
            ))],
        ];
    }

    public function mode(): string
    {
        return (string) $this->validated('autonomy_mode');
    }
}
