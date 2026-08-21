<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Profiles;

/**
 * A suggestion with its working shown.
 *
 * The advisor never installs anything, so the value of its answer is entirely
 * in the reasons: an operator who disagrees with the suggestion needs to see
 * which observation led to it to know whether the machine or the advisor is
 * wrong. A bare enum would be an oracle; this is an argument.
 */
final readonly class ProfileAdvice
{
    /**
     * @param  list<string>  $reasons  why this profile, each tied to an observation
     * @param  list<string>  $cautions  true today and worth knowing before installing
     * @param  list<string>  $alternatives  profiles that are choices rather than detections
     */
    public function __construct(
        public InstallationProfile $suggestion,
        public array $reasons,
        public array $cautions,
        public array $alternatives,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'suggestion' => $this->suggestion->value,
            'label' => $this->suggestion->label(),
            'reasons' => $this->reasons,
            'cautions' => $this->cautions,
            'alternatives' => $this->alternatives,
        ];
    }
}
