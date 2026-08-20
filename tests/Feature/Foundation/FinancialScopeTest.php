<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * SaniTube does not handle money, and this is where that stops being a promise.
 *
 * The scope is deliberate: earnings stay with the distributor. Credits and
 * rights *metadata* are in scope because a distributor requires them to
 * publish — a ℗ line, a composer credit, a contributor role. What anybody is
 * paid is not.
 *
 * **Written now rather than when it is nearly too late.** DIST-003's research
 * found that the distributor most likely to be integrated first publishes an
 * API covering royalties, splits and payouts alongside delivery. The temptation
 * is real precisely because the data will be *right there* in responses an
 * adapter already receives, and dropping it has to be a deliberate, visible act
 * rather than an incidental one. A guardrail that exists before the adapter
 * fails on the commit that adds the field, which is when it is cheap; one added
 * afterwards has to argue with code somebody has already shipped.
 *
 * Individual modules already assert this about themselves — {@see
 * \Tests\Feature\AI\SpendSafetyTest}, the generation spend guard, the release
 * package. Those are per-ticket promises about files their authors were
 * thinking about. This one sweeps whole modules, so a *new* file in them is
 * covered without anybody remembering to extend a list.
 */
final class FinancialScopeTest extends TestCase
{
    /**
     * Modules that must contain no financial vocabulary at all.
     *
     * Distribution is the important one and the reason this exists.
     *
     * @var list<string>
     */
    private const MODULES = [
        'src/Distribution',
        'src/Releases',
        'src/Production',
        'src/Editorial',
        'src/Enrichment',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN = [
        'royalt',
        'payout',
        'revenue',
        'earning',
        'invoice',
        'accounting',
        'currency',
        'usd',
        'eur',
        'gbp',
    ];

    #[Test]
    public function no_module_in_scope_handles_money(): void
    {
        $offenders = [];

        foreach ($this->files() as $file) {
            $code = strtolower($this->codeWithoutComments($file));

            foreach (self::FORBIDDEN as $word) {
                if (str_contains($code, $word)) {
                    $offenders[] = $this->relative($file).' names ['.$word.']';
                }
            }
        }

        $this->assertSame([], $offenders, 'Financial vocabulary found: '.implode('; ', $offenders));
    }

    #[Test]
    public function every_named_module_is_actually_reached(): void
    {
        // "No offenders" is also what a scan that read nothing returns. A
        // total-file threshold would be a magic number that drifts; what
        // actually goes wrong is a module being renamed or moved and silently
        // contributing zero files while the sweep still reports a pass.
        //
        // Two guardrails this session passed while covering a fraction of what
        // they claimed, so this asserts per module rather than in aggregate.
        $files = $this->files();

        foreach (self::MODULES as $module) {
            $matched = array_filter(
                $files,
                fn (string $file): bool => str_starts_with($this->relative($file), $module.'/'),
            );

            $this->assertNotEmpty($matched, $module.' contributed no files, so the sweep did not cover it.');
        }
    }

    #[Test]
    public function the_scan_would_actually_catch_something(): void
    {
        // The guardrail's own guardrail. A scanner is only evidence if it fails
        // on the thing it exists to find, and this asserts that on real text
        // rather than trusting the loop above to be right.
        $sample = '<?php $royaltyRate = 0.7; // a comment mentioning nothing';

        $found = [];

        foreach (self::FORBIDDEN as $word) {
            if (str_contains(strtolower($sample), $word)) {
                $found[] = $word;
            }
        }

        $this->assertSame(['royalt'], $found);
    }

    #[Test]
    public function comments_are_stripped_before_scanning(): void
    {
        // Not a nicety. Twice this session a docblock has failed a guardrail by
        // promising the very thing it was asserting the absence of — a money
        // test that tripped on its own explanation, and a storage test that
        // tripped on a sentence saying there was no bucket. Explaining why
        // something is excluded requires naming it.
        $file = tempnam(sys_get_temp_dir(), 'sanitube-scope-');
        $this->assertIsString($file);

        file_put_contents($file, "<?php\n/** No royalty is held here. */\n\$x = 1;\n");

        try {
            $this->assertStringNotContainsString('royalt', strtolower($this->codeWithoutComments($file)));
        } finally {
            @unlink($file);
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        foreach (self::MODULES as $module) {
            $root = base_path($module);

            if (! is_dir($root)) {
                continue;
            }

            // Walked recursively rather than globbed: GEN-005 found a pattern
            // with a doubled wildcard silently covering one depth and passing.
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function codeWithoutComments(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
