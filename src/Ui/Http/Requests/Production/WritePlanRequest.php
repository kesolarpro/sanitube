<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Production\Enums\AutonomyMode;

/**
 * Making a plan, or correcting one.
 *
 * PROD-002. One request for both, because the fields and their bounds are the
 * same in both directions and a second copy of them is one that drifts. The
 * difference is which are required: creating needs a name and an imprint,
 * correcting needs whatever is being corrected.
 *
 * **`status` is not a field here and never will be.** Pausing, resuming and
 * disabling are named actions with their own routes; a settable status would
 * present resuming as an assignment and would put `EXHAUSTED` — a conclusion
 * the platform draws from its own counting — within reach of a form.
 *
 * The bounds are the interesting part. `cadence_days` and `target_track_count`
 * are the two numbers that decide how often this installation pays a supplier
 * without being asked, so they are bounded to something a person could have
 * meant: a cadence of ten years is as far as a standing intention goes, and a
 * target of a hundred thousand tracks is a typo rather than an ambition.
 */
final class WritePlanRequest extends FormRequest
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
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:255'],

            // The imprint. Required on creation because a plan produces *in
            // the manner of* something and there is no default manner; the
            // writer refuses an inactive one, so this rule cannot be the only
            // guard and is not meant to be.
            'editorial_profile' => [$creating ? 'required' : 'nullable', 'string', 'max:36'],

            'autonomy_mode' => ['nullable', 'string', Rule::in(array_map(
                static fn (AutonomyMode $case): string => $case->value,
                AutonomyMode::cases(),
            ))],

            // Nullable and meaning it: a plan with no target runs until
            // somebody stops it, which is a legitimate standing intention and
            // the shipped shape of an open-ended imprint.
            'target_track_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'cadence_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The fields the writer understands, with absent ones left absent.
     *
     * `array_key_exists` rather than a null check throughout, because the
     * writer distinguishes "not mentioned" from "set to nothing" — clearing a
     * target and never having had one are different intentions.
     *
     * Deliberately not named `attributes()`: that name belongs to
     * `FormRequest`, where it means the display names used in validation
     * messages, and overriding it with values would quietly break every
     * message this form can produce.
     *
     * @return array<string, mixed>
     */
    public function planAttributes(): array
    {
        $validated = $this->validated();
        $attributes = [];

        foreach (['name', 'autonomy_mode', 'target_track_count', 'cadence_days', 'notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        return $attributes;
    }

    public function profileUuid(): ?string
    {
        $uuid = $this->validated('editorial_profile');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
