<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Why the platform is being stopped.
 *
 * OPS-002. **A machine code, never a sentence.** The service says so and the
 * reason for it is the interface: the code is rendered in the reader's
 * language, and free text typed by whoever pressed the button would be a
 * sentence in one language shown to everybody, sitting in an audit record and
 * on a shared screen forever.
 *
 * Free text would also be the place somebody eventually pastes a credential or
 * a customer's name into a field nobody thought of as data.
 *
 * The list is short on purpose. A vocabulary long enough to describe every
 * situation is one nobody reads to the end of, and the useful question a week
 * later is which *kind* of thing happened.
 */
final class PauseBackgroundWorkRequest extends FormRequest
{
    /**
     * Every reason this switch may be pressed for, in the order they happen.
     *
     * @var list<string>
     */
    public const REASONS = [
        // The default, and the honest one for "I am looking at something and
        // want it to stop while I look".
        'OPERATOR_REQUEST',

        // A supplier is answering badly or expensively, and the circuit
        // breaker's window is not the right instrument.
        'PROVIDER_TROUBLE',

        // Disk, memory, or a host limit. Distinct from provider trouble
        // because the thing to fix is here rather than there.
        'HOST_PRESSURE',

        // A deploy, a migration, a restore. Planned, and worth telling apart
        // from the three above when reading back.
        'MAINTENANCE',
    ];

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
            'reason' => ['nullable', 'string', Rule::in(self::REASONS)],
        ];
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        // Absent means the ordinary case. A required field here would make the
        // emergency control need a decision before it works.
        return is_string($reason) && $reason !== '' ? $reason : 'OPERATOR_REQUEST';
    }
}
