<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Services\ValidateReleaseIdentifiers;
use SaniTube\Foundation\Validation\ValidationIssue;
use SaniTube\Releases\Exceptions\ReleasePackagingException;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Packaging\PackageRelease;

/**
 * What would actually cross to a distributor, and what still stops it.
 *
 * REL-003 built {@see PackageRelease} and gave it no caller. It is the single
 * description of a release that leaves this platform, and until this ticket the
 * only way to see one was to write a test — so nobody assembling a release
 * could find out what a store would receive until a store had received it.
 *
 * **Three answers, and they are deliberately separate**, because a label can be
 * blocked by any one of them while the other two are fine:
 *
 *   - **The package.** Either it assembled, in which case this *is* the
 *     content, or it refused with a code. Nothing is approximated: the same
 *     service a delivery would use is the one that runs here.
 *   - **The identifiers.** {@see ValidateReleaseIdentifiers} — a UPC and an
 *     ISRC per track. These block every delivery, to every distributor, and
 *     before REL-004 they were only reachable through a *configured*
 *     destination. On an installation with none, a release with no ISRC at all
 *     passed validation, went READY, and said nothing.
 *   - **Whether anywhere exists to send it.** A perfect package on an
 *     installation with no distributor configured is still not going anywhere,
 *     and saying so is the difference between an operator changing a setting
 *     and an operator re-checking a tracklist that was never the problem.
 *
 * **A package that assembles is not a package that may be delivered**, and this
 * never implies otherwise. Packaging asks REL-001's validator; delivery also
 * asks the identifiers and the distributor itself. Reporting "prepared" as
 * though it meant "ready to send" would be the same class of mistake as
 * treating an unreachable distributor as approval.
 */
final readonly class ReleasePackageQuery
{
    public function __construct(
        private PackageRelease $packager,
        private ValidateReleaseIdentifiers $identifiers,
        private DistributorManager $distributors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forRelease(Release $release): array
    {
        $answer = [
            'release' => $release->uuid,

            'blocking_identifiers' => array_map(
                static fn (ValidationIssue $issue): array => $issue->toArray(),
                $this->identifiers->handle($release),
            ),

            // Not which ones, and not their names: the question the screen asks
            // is whether this installation can deliver at all. The destination
            // screen is where they are listed, with their own availability.
            'destinations_configured' => $this->destinations(),
        ];

        try {
            $package = $this->packager->handle($release);
        } catch (ReleasePackagingException $exception) {
            // The code, and the codes it carries. `getMessage()` is written for
            // a developer reading a log and names internal concepts; it does
            // not travel.
            return array_merge($answer, [
                'prepared' => false,
                'refusal' => $exception->reason,
                'refusal_context' => $exception->context,
                'package' => null,
            ]);
        }

        return array_merge($answer, [
            'prepared' => true,
            'refusal' => null,
            'refusal_context' => [],
            'package' => $package->toArray(),
        ]);
    }

    /**
     * How many real destinations exist.
     *
     * `none` is excluded. It is the name of *not having* a distributor, and
     * counting it would let this screen report a destination that cannot
     * receive anything — the same reason ReleaseDistributionQuery skips it.
     */
    private function destinations(): int
    {
        $count = 0;

        foreach ($this->distributors->names() as $name) {
            if ($name !== DistributorManager::DISABLED) {
                $count++;
            }
        }

        return $count;
    }
}
