<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * READY-001. Keeping the readiness ledger's checkable half checkable.
 *
 * `docs/production-readiness.md` is the document a release decision gets made
 * from, and the audit that produced this test found it had drifted — in the
 * *pessimistic* direction, which is the less dangerous failure and just as
 * corrosive. Four controls were marked NOT_READY that had since been built, and
 * an entire subsystem had no row at all.
 *
 * **This test does not claim to catch that.** Nothing links a sentence in a
 * table to the feature it describes, and inventing such a link — a marker per
 * row, a registry of controls — would be a second ledger to keep in step with
 * the first. The drift that matters is found by somebody reading the code, and
 * the page records when that last happened.
 *
 * What *is* mechanical is the page's references to things that exist: the
 * commands it tells an operator to run, and the decisions it cites. A command
 * renamed in a refactor turns an instruction into a dead end, and an ADR number
 * that points at nothing turns a justification into an assertion. Both are
 * silent, both are cheap to catch, and neither needs a second list.
 */
final class ProductionReadinessLedgerTest extends TestCase
{
    #[Test]
    public function every_command_the_ledger_names_is_one_this_build_registers(): void
    {
        $registered = array_keys($this->app->make(Kernel::class)->all());
        $named = $this->mentioned('/`(?:php artisan )?(sanitube:[a-z0-9:-]+)/');

        // A page that names none would pass every assertion it does not make.
        $this->assertGreaterThan(5, count($named));

        foreach ($named as $command) {
            $this->assertContains(
                $command,
                $registered,
                sprintf('production-readiness.md tells an operator to run [%s], which this build has no command for.', $command),
            );
        }
    }

    #[Test]
    public function every_decision_the_ledger_cites_is_one_that_was_written_down(): void
    {
        $cited = $this->mentioned('/\b(ADR-\d{4})\b/');

        $this->assertNotSame([], $cited);

        foreach ($cited as $adr) {
            $this->assertNotSame(
                [],
                glob(base_path('docs/adr/'.$adr.'-*.md')) ?: [],
                sprintf('production-readiness.md justifies a verdict with [%s], which does not exist.', $adr),
            );
        }
    }

    /**
     * The vocabulary is four words, and the page says so in its own header.
     *
     * A fifth would not be a typo: the whole point of the document is that
     * BLOCKED_EXTERNAL and NOT_READY mean different things, and a row that
     * invents `PARTIAL` or `IN_PROGRESS` is a row that has stopped making the
     * distinction.
     */
    #[Test]
    public function no_row_invents_a_fifth_verdict(): void
    {
        $allowed = ['READY', 'NOT_READY', 'BLOCKED_EXTERNAL', 'NOT_REQUIRED'];
        $rows = 0;

        foreach (explode("\n", $this->ledger()) as $line) {
            // A table row: | control | verdict | notes |. The header and the
            // separator have no verdict column worth reading.
            if (! preg_match('/^\|[^|]+\|\s*\**([A-Z_]+)\**\s*\|/', $line, $found)) {
                continue;
            }

            $rows++;

            $this->assertContains(
                $found[1],
                $allowed,
                sprintf('A row claims [%s], which is not one of the four verdicts this page defines.', $found[1]),
            );
        }

        $this->assertGreaterThan(50, $rows, 'The ledger was read as almost empty, which means it was not read.');
    }

    /**
     * @return list<string>
     */
    private function mentioned(string $pattern): array
    {
        preg_match_all($pattern, $this->ledger(), $found);

        /** @var list<string> $values */
        $values = array_values(array_unique($found[1]));

        sort($values);

        return $values;
    }

    /**
     * The summary's counts are the ledger's, or they are a second ledger.
     *
     * `docs/final-report.md` opens with how many rows carry each verdict, and
     * those four numbers are the ones a reader takes away — "eight things
     * remain" is the sentence that survives the meeting. They are also the
     * first thing to go stale: a row moved from NOT_READY to READY changes the
     * table under test and leaves the summary saying what was true last month.
     *
     * Counted from the ledger rather than kept beside it. The report says what
     * the rows mean; the rows say how many there are.
     */
    #[Test]
    public function the_final_report_counts_what_the_ledger_actually_holds(): void
    {
        $counts = [];

        foreach (explode("\n", $this->ledger()) as $line) {
            if (preg_match('/^\|[^|]+\|\s*\**([A-Z_]+)\**\s*\|/', $line, $found) === 1) {
                $counts[$found[1]] = ($counts[$found[1]] ?? 0) + 1;
            }
        }

        $this->assertNotSame([], $counts, 'No verdict rows were read, so nothing below was checked.');

        $report = $this->report();

        foreach ($counts as $verdict => $count) {
            $this->assertMatchesRegularExpression(
                sprintf('/^\|\s*%s\s*\|\s*%d\s*\|/m', preg_quote($verdict, '/'), $count),
                $report,
                sprintf(
                    'The final report does not say there are %d rows marked %s. Recount it against the ledger '
                        .'rather than leaving a summary that was true last month.',
                    $count,
                    $verdict,
                ),
            );
        }
    }

    private function report(): string
    {
        $path = base_path('docs/final-report.md');

        if (! is_file($path)) {
            $this->fail('docs/final-report.md is missing. The ledger points at it as the summary.');
        }

        return (string) file_get_contents($path);
    }

    private function ledger(): string
    {
        $path = base_path('docs/production-readiness.md');

        if (! is_file($path)) {
            $this->fail('docs/production-readiness.md is missing. It is the document a release decision is made from.');
        }

        return (string) file_get_contents($path);
    }
}
