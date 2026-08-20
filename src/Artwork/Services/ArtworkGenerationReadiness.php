<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\Enums\ArtworkRefusal;

/**
 * Whether this installation could generate a cover, and if not, which numbers
 * disagree.
 *
 * ART-003. `ArtworkFeasibility` has known the answer since ART-002 and had no
 * caller, so an operator who configured an image provider and expected covers
 * got nothing and no explanation. The information existed; it was not on a
 * screen.
 *
 * **The contradiction is stated, never worked around.** ART-002 found that the
 * documented square sizes of the image models available today are considerably
 * smaller than this platform's default requirement of a 3000px shortest side.
 * Both are settings, so an operator has two honest ways out — lower the
 * requirement, or configure a provider that can reach it — and this reports the
 * two numbers so they can choose. Reporting "generation unavailable" alone
 * would leave somebody re-reading provider documentation for an afternoon.
 *
 * **Nothing here spends anything.** It compares two configurations. That is why
 * it can be asked on every render of a release screen, and why it is separate
 * from the generator itself.
 */
final readonly class ArtworkGenerationReadiness
{
    public function __construct(
        private ImageProvider $provider,
        private ArtworkFeasibility $feasibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        if (! $this->provider->isAvailable()) {
            // The ordinary state of a fresh installation, and not a fault.
            // Artwork is attached by hand, exactly as before.
            return [
                'available' => false,
                'refusal' => ArtworkRefusal::NoProvider->value,
                'detail' => [],
            ];
        }

        $size = $this->feasibility->bestUsableSize();

        if ($size === null) {
            return [
                'available' => false,
                'refusal' => ArtworkRefusal::RequirementsUnreachable->value,
                // The numbers, so the refusal is something a person can act on.
                'detail' => $this->stringify($this->feasibility->explain()),
            ];
        }

        return [
            'available' => true,
            'refusal' => null,
            'detail' => [
                'provider' => $this->provider->name(),
                'size' => $size->width.'×'.$size->height,
            ],
        ];
    }

    /**
     * @param  array<string, int|string|null>  $explanation
     * @return array<string, string>
     */
    private function stringify(array $explanation): array
    {
        $detail = [];

        foreach ($explanation as $key => $value) {
            // Absent rather than null: a screen that prints "required minimum:
            // —" is describing a requirement nobody set as though it were part
            // of the disagreement.
            if ($value !== null) {
                $detail[$key] = (string) $value;
            }
        }

        return $detail;
    }
}
