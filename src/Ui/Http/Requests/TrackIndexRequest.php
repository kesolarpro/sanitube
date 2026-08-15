<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackSource;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Ui\Queries\TrackIndexQuery;

/**
 * The filters the track list accepts, and the ones it refuses.
 *
 * The first version of this screen quietly discarded a value it did not
 * recognise: `?status=NOT_A_STATUS` returned the entire catalogue while the
 * form went on displaying that status. That is the worst of both readings — the
 * operator believes they are looking at a filtered list, and they are not.
 *
 * So an unrecognised value is now a 422. The vocabulary comes from the domain's
 * own enums rather than from a copy of them kept here, because a copy is a
 * thing that drifts the first time a case is added.
 *
 * Refusing rather than ignoring also removes the question of whether an
 * attacker-supplied string could reach SQL: it never leaves validation.
 */
final class TrackIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation is the route's job — `auth` and `active` from SEC-001.
        // Repeating it here would mean two places to change and one to forget.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', Rule::enum(TrackStatus::class)],
            'source' => ['nullable', Rule::enum(TrackSource::class)],
            'language' => ['nullable', 'string', 'max:16'],
            // Present-but-empty is how a <select> submits "no preference", so
            // an empty string is accepted and canonicalised away below rather
            // than rejected.
            'instrumental' => ['nullable', 'in:0,1,'],
            'explicit' => ['nullable', 'in:0,1,'],
            'artist' => ['nullable', 'uuid'],
            'artist_role' => ['nullable', Rule::enum(TrackArtistRole::class)],
            // A cursor is opaque to the reader but not arbitrary: it either
            // came from this list or it did not. Without this check a mangled
            // URL reaches the paginator and becomes a 500.
            'cursor' => [
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! TrackIndexQuery::describesThisOrdering($value)) {
                        $fail('The pagination cursor is not one this list issued.');
                    }
                },
            ],
        ];
    }

    /**
     * A refused filter answers 422, not a redirect.
     *
     * Laravel's default for a non-JSON request is a 302 back with the errors in
     * the session, which is the right shape for a form POST and the wrong shape
     * here: this is a GET whose parameters are the address of a list, and there
     * is no previous page to send the reader back to that would not have the
     * same bad parameters in it.
     *
     * A worthwhile tension to record: Inertia's own convention is the 302, and
     * a 422 currently reaches the browser as a JSON body rather than as an
     * error rendered inside the filter form. That is a real limitation, and the
     * fix is inline error display on the filter form — not accepting the
     * parameter and pretending it applied. Refusing loudly is the behaviour
     * that must not regress; presenting the refusal nicely is a follow-up.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'These filters are not ones the catalogue understands.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * The validated filters, canonicalised.
     *
     * Empty strings become nulls and the booleans become booleans, so the read
     * model receives values it can use directly instead of re-deriving what the
     * query string meant. Everything here has already been checked against the
     * domain enums.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['search', 'status', 'source', 'language', 'artist', 'artist_role'] as $key) {
            $value = $this->validated($key);
            $value = is_string($value) ? trim($value) : null;

            $filters[$key] = ($value === null || $value === '') ? null : $value;
        }

        foreach (['instrumental', 'explicit'] as $key) {
            $value = $this->validated($key);

            $filters[$key] = ($value === '0' || $value === '1') ? $value === '1' : null;
        }

        return $filters;
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
