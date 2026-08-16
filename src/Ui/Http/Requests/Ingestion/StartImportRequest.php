<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Ingestion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Ingestion\Enums\IngestionSource;

/**
 * What to import.
 *
 * A prefix **or** a list of object keys. Which of the two, and whether the
 * selection is empty, is `StartIngestionBatch`'s question and not restated
 * here — "importing an entire store blindly is never what someone meant" is a
 * property of the request the domain refuses, and a form that also knew it
 * would be a second copy a future API caller does not go through.
 *
 * What *is* here is shape: a source this platform implements, strings that are
 * strings, and a list short enough that the request is not itself the denial
 * of service.
 */
final class StartImportRequest extends FormRequest
{
    /**
     * Above what a person types by hand and far below the batch limit the
     * domain enforces. A list longer than this is a manifest, and a manifest
     * is the command's job — a browser that has to hold ten thousand object
     * keys in a form field is a browser that will drop some of them.
     */
    private const MAX_REFERENCES = 500;

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
            'source' => ['sometimes', 'string', Rule::enum(IngestionSource::class)],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'references' => ['sometimes', 'array', 'max:'.self::MAX_REFERENCES],
            'references.*' => ['string', 'max:1024'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function source(): IngestionSource
    {
        $source = $this->input('source');

        return is_string($source)
            ? (IngestionSource::tryFrom($source) ?? IngestionSource::CloudImport)
            : IngestionSource::CloudImport;
    }

    public function prefix(): ?string
    {
        $prefix = $this->input('prefix');

        if (! is_string($prefix) || trim($prefix) === '') {
            return null;
        }

        return trim($prefix);
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        /** @var array<int, mixed> $references */
        $references = (array) $this->input('references', []);

        $keys = [];

        foreach ($references as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                $keys[] = trim($reference);
            }
        }

        return array_values(array_unique($keys));
    }

    public function provider(): ?string
    {
        $provider = $this->input('provider');

        if (! is_string($provider) || trim($provider) === '') {
            return null;
        }

        return trim($provider);
    }
}
