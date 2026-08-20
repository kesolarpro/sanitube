<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Ingestion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A browser declaring the files it is about to write straight to storage.
 *
 * Names and declared media types only — no bytes, and no keys. The key is the
 * server's to choose: a client that named its own would be naming a path, and
 * a path a client controls is a path that eventually contains `..`.
 *
 * The declared type is a courtesy, used to refuse an obviously wrong file
 * before a capability is minted rather than after it has been used. It is not
 * evidence: what decides is measured from the object once it has landed.
 */
final class DepositCapabilitiesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*.name' => ['required', 'string', 'max:255'],
            'files.*.type' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return list<array{name: string, type: string|null}>
     */
    public function files(): array
    {
        $declared = $this->input('files');

        if (! is_array($declared)) {
            return [];
        }

        $files = [];

        foreach ($declared as $file) {
            if (! is_array($file) || ! is_string($file['name'] ?? null)) {
                continue;
            }

            $type = $file['type'] ?? null;

            $files[] = [
                'name' => $file['name'],
                'type' => is_string($type) && trim($type) !== '' ? trim($type) : null,
            ];
        }

        return $files;
    }
}
