<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use SaniTube\Ui\Settings\WritableSettings;

/**
 * What the settings form is allowed to say.
 *
 * The rules come from {@see WritableSettings}, not from a list repeated here.
 * A second copy of "what may be written" is the copy that eventually permits a
 * key the writer refuses, or refuses one the screen offers — and this form is
 * the platform's only route from a browser to a `.env` file.
 *
 * `validated()` returns only declared keys, which is what makes anything else
 * in the body inert rather than merely ignored.
 */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is behind `can.role:administer`. A second copy of an
        // authorisation decision is the copy that drifts.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return app(WritableSettings::class)->rules();
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }
}
