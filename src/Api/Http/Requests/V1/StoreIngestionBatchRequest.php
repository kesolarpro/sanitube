<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use SaniTube\Ingestion\Enums\IngestionSource;

/**
 * The request that starts an import.
 *
 * It carries *references*, never payloads. Nine hundred files do not fit in an
 * HTTP request, and a request that tried would fail somewhere between the
 * client's timeout and the server's `post_max_size` — every time, at a
 * different point, with nothing importable to show for it. The bytes arrive
 * through the storage pipeline; this names what to fetch.
 *
 * Exactly one of `prefix` or `references` is required. Neither would mean
 * "import the entire store", which is never what anyone means and is how a
 * platform ingests its own output.
 */
final class StoreIngestionBatchRequest extends FormRequest
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
        $implemented = array_values(array_map(
            static fn (IngestionSource $source): string => $source->value,
            array_filter(IngestionSource::cases(), static fn (IngestionSource $s): bool => $s->isImplemented()),
        ));

        return [
            'source' => ['required', Rule::in($implemented)],
            'provider' => ['sometimes', 'string', 'max:64'],
            'prefix' => ['sometimes', 'string', 'max:1024'],
            'references' => ['sometimes', 'array', 'min:1'],
            'references.*' => ['string', 'max:1024'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasPrefix = is_string($this->input('prefix')) && trim((string) $this->input('prefix')) !== '';
            $hasReferences = is_array($this->input('references')) && $this->input('references') !== [];

            if (! $hasPrefix && ! $hasReferences) {
                $validator->errors()->add(
                    'prefix',
                    'Provide either a prefix or an explicit list of references. Importing an entire '
                        .'store blindly is not supported.',
                );
            }

            if ($hasPrefix && $hasReferences) {
                $validator->errors()->add(
                    'prefix',
                    'Provide a prefix or references, not both — otherwise it is ambiguous which one '
                        .'decided what was imported.',
                );
            }
        });
    }

    public function source(): IngestionSource
    {
        return IngestionSource::from((string) $this->input('source'));
    }

    public function prefix(): ?string
    {
        $prefix = $this->input('prefix');

        return is_string($prefix) && trim($prefix) !== '' ? trim($prefix) : null;
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        /** @var array<int, mixed> $references */
        $references = (array) $this->input('references', []);

        return array_values(array_filter(
            array_map(static fn (mixed $r): string => is_string($r) ? trim($r) : '', $references),
            static fn (string $r): bool => $r !== '',
        ));
    }

    public function provider(): ?string
    {
        $provider = $this->input('provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }
}
