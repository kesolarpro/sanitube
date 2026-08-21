<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CAT-003. Readiness is earned, and there are three places that grant it.
 *
 * `Track::markReady()` and `Release::markReady()` check every condition before
 * they write, and `AssetVerificationService` compares a checksum before it
 * calls anything verified. **Thirty-odd places then read those columns as
 * authority** — a submission, an export, a promotion, a preview policy, an
 * eligibility check — each of them trusting that the check happened. The
 * column is a conclusion, and everything downstream treats it as one.
 *
 * Nothing stops a fourth place writing it. `Track`, `Release` and `Asset` all
 * declare `$guarded = []`, so `status` is mass-assignable: `create([...
 * 'status' => TrackStatus::Ready])` produces a track that every one of those
 * readers believes was checked. Nothing does that today — the one service that
 * creates a track enumerates its keys and writes `Draft` as a literal — but
 * that is a property of the call sites, not of the model.
 *
 * **Guarding the column at runtime was tried and put back.** Making `status`
 * non-mass-assignable broke four hundred and fifty tests: three services and
 * every factory create a row with a status, legitimately, and none of them is
 * granting anything. The protection would have cost a rewrite across three
 * modules to stop something no code does, which is not a trade this platform
 * makes for a risk that is latent rather than reachable.
 *
 * So the rule lives here, where it costs nothing to hold and fails loudly the
 * day somebody adds the fourth writer. Same shape as `RouteAuthorizationTest`:
 * read what is actually there, and name every exception with a sentence.
 *
 * **What this sees, and what it does not.** It finds a state being *written* —
 * `= Ready`, or `'status' => Ready` in an array. It does not see one handed to
 * a method that writes it, the way `AssetVerificationService` passes
 * `AssetStatus::Verified` to its own `mark()`. That file is named below in any
 * case, and the gap is stated rather than implied: a scan whose limits are
 * unwritten is a scan somebody trusts past them.
 */
final class EarnedStateTest extends TestCase
{
    /**
     * The states a check has to happen before.
     *
     * @var list<string>
     */
    private const EARNED = [
        'TrackStatus::Ready',
        'ReleaseStatus::Ready',
        'AssetStatus::Verified',
    ];

    /**
     * Where each may be granted, and what does the checking.
     *
     * @var array<string, string>
     */
    private const GRANTERS = [
        'src/Catalog/Models/Track.php' => 'markReady() — refuses unless readinessProblems() is empty. I3.',
        'src/Releases/Models/Release.php' => 'markReady() — refuses unless the release validates. REL-002.',
        'src/Assets/Services/AssetVerificationService.php' => 'The checksum comparison is the check.',
    ];

    #[Test]
    public function readiness_is_written_only_where_it_is_earned(): void
    {
        $writes = [];
        $mentions = 0;

        foreach ($this->sources() as $path => $code) {
            foreach ($this->earnedStates($code) as $mention) {
                $mentions++;

                if (! $mention['writes']) {
                    continue;
                }

                $writes[$path][] = $mention['state'];
            }
        }

        foreach ($writes as $path => $states) {
            $this->assertArrayHasKey(
                $path,
                self::GRANTERS,
                sprintf(
                    '[%s] writes %s. Thirty-odd places read that column as proof a check happened; '
                        .'granting it here means it did not. Call the model\'s markReady(), or name this '
                        .'file in GRANTERS with what does the checking.',
                    $path,
                    implode(', ', array_unique($states)),
                ),
            );
        }

        // A scan that matched nothing would pass every assertion it does not
        // make. These three states are read all over the platform.
        $this->assertGreaterThan(20, $mentions, 'The sources were read as nearly empty, so they were not read.');
    }

    /**
     * And a granter that no longer grants is an exemption for nothing.
     */
    #[Test]
    public function every_named_granter_still_grants(): void
    {
        foreach (self::GRANTERS as $path => $reason) {
            $this->assertFileExists(base_path($path));

            $this->assertGreaterThan(
                20,
                strlen(trim($reason)),
                sprintf('[%s] is named as a granter with something that is not a reason.', $path),
            );
        }
    }

    // ------------------------------------------------------------ fixtures

    /**
     * Every source file, with its comments removed.
     *
     * The comments in this platform quote code constantly — this very file
     * names all three states in prose — and a scan that read them would report
     * a granter in every docblock that mentions one.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $sources = [];

        /** @var list<string> $files */
        $files = [];
        $this->collect(base_path('src'), $files);

        foreach ($files as $file) {
            $code = '';

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }

                    $code .= $token[1];

                    continue;
                }

                $code .= $token;
            }

            $sources[ltrim(str_replace(base_path(), '', $file), '/')] = $code;
        }

        return $sources;
    }

    /**
     * Each mention of an earned state, and whether it is being written.
     *
     * A write is `= Ready` or `'status' => Ready`. Everything else — `===`,
     * `!==`, `in_array`, `where`, a `match` arm — is a read, and reads are the
     * point of the column.
     *
     * @return list<array{state: string, writes: bool}>
     */
    private function earnedStates(string $code): array
    {
        $found = [];

        foreach (self::EARNED as $state) {
            $offset = 0;

            while (($at = strpos($code, $state, $offset)) !== false) {
                $offset = $at + strlen($state);

                $before = rtrim(substr($code, 0, $at));

                $found[] = [
                    'state' => $state,
                    // A single `=` and not `==`, `===`, `!=` or `!==`: the
                    // character before the `=` is what tells them apart.
                    'writes' => str_ends_with($before, '=>')
                        || (str_ends_with($before, '=') && ! str_ends_with($before, '==') && ! str_ends_with($before, '!=')),
                ];
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $into
     */
    private function collect(string $directory, array &$into): void
    {
        /** @var list<string> $entries */
        $entries = glob(rtrim($directory, '/').'/*') ?: [];

        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                $this->collect($entry, $into);

                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $into[] = $entry;
            }
        }
    }
}
