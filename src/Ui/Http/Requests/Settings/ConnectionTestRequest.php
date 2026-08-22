<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Ui\Settings\ConnectionProbe;

/**
 * Which subsystem to probe.
 *
 * A closed vocabulary, and not one written out here: the rule is derived from
 * {@see ConnectionProbe}, which is the same source the settings payload draws
 * the word from. A target that named a provider, a URL or a disk would turn a
 * settings button into a request the caller composes, which is how a settings
 * screen becomes an SSRF tool. What may be probed is what this installation
 * has configured, and the controller reads that from configuration itself.
 */
final class ConnectionTestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target' => ['required', 'string', Rule::enum(ConnectionProbe::class)],
        ];
    }

    public function target(): ConnectionProbe
    {
        return ConnectionProbe::from($this->string('target')->toString());
    }
}
