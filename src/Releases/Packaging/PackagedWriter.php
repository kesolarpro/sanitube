<?php

declare(strict_types=1);

namespace SaniTube\Releases\Packaging;

/**
 * Somebody who wrote the work, as a distributor needs to see them.
 *
 * **Separate from {@see PackagedContributor} on purpose.** That one is the
 * recording: an engineer, a mixer, a producer. This one is the *work* — the
 * composer, the lyricist, the publisher — and the two are different rights held
 * by different people, often registered with different societies. A single list
 * would let a reader take a mastering engineer for a rights holder, which is
 * the kind of mistake nobody notices until a royalty statement is wrong.
 *
 * The distinction is also why this type exists rather than a `role` value on
 * the other: only a writer credit carries a `share`, and a shape that had the
 * field for everybody would invite somebody to fill it in for a mixer.
 *
 * **`share` is rights metadata, never money.** It is the split as captured in
 * the catalogue — what a society is told about who wrote what — and nothing in
 * this platform computes anything from it. SaniTube does not handle earnings;
 * they stay with the distributor.
 */
final readonly class PackagedWriter
{
    public function __construct(
        // The legal name, as with a contributor: a society matches on the name
        // somebody is registered under, not the one they perform as.
        public string $name,
        public string $role,
        /** The captured split, as a decimal string, or null when none was recorded. */
        public ?string $share = null,
        public ?string $ipi = null,
        public ?string $isni = null,
    ) {}
}
