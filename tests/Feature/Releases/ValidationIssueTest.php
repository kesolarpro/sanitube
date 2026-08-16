<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Releases\ReleaseValidationIssue;
use SaniTube\Releases\ReleaseValidationResult;
use Tests\TestCase;

/**
 * REL-002 — validation as data rather than as English.
 *
 * Before this, `ValidateRelease` answered in sentences, and those sentences
 * became a contract twice over by accident: the interface rendered them
 * verbatim to a French label, and `SubmitDelivery` decided whether a
 * distributor had been unreachable by searching one for a substring. The
 * second is the dangerous one — a reworded message would silently have turned
 * an outage into a rejection, and a rejection is a verdict.
 *
 * The claims here:
 *
 *   - **A code is the contract.** Nothing in the platform decides anything
 *     from a message.
 *   - **Every code a person can see has six translations**, and the test walks
 *     the source rather than a hand-kept list, because a hand-kept list is one
 *     somebody forgets to add to.
 *   - **Context carries values, never sentences**, so each locale can put a
 *     track number in its own word order.
 */
final class ValidationIssueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_code_the_domain_can_emit_is_translated_in_every_locale(): void
    {
        $codes = $this->codesInSource();

        $this->assertGreaterThan(15, count($codes), 'The scan found suspiciously few codes.');

        foreach (['en', 'fr', 'es', 'it', 'pt', 'de'] as $locale) {
            /** @var array<string, mixed> $lines */
            $lines = require base_path('lang/'.$locale.'/ui.php');
            /** @var array<string, string> $issues */
            $issues = $lines['releases']['issue'] ?? [];

            foreach ($codes as $code) {
                // Every code, with no exemption list. A list of codes that do
                // not need translating is a list somebody adds to instead of
                // writing a translation; if one ever genuinely does not need
                // one, that is the moment to introduce the mechanism and say
                // why — not to keep an empty escape hatch open.
                $this->assertArrayHasKey(
                    $code,
                    $issues,
                    sprintf('[%s] has no %s translation. A code without one renders as the code.', $code, $locale),
                );
            }
        }
    }

    #[Test]
    public function every_translated_code_still_exists_in_the_domain(): void
    {
        /** @var array<string, mixed> $lines */
        $lines = require base_path('lang/en/ui.php');
        /** @var array<string, string> $issues */
        $issues = $lines['releases']['issue'] ?? [];

        $codes = $this->codesInSource();

        foreach (array_keys($issues) as $translated) {
            // The other direction. A translation for a code nothing emits any
            // more is dead weight in six files, and it is how a locale drifts
            // out of step without the completeness gate noticing.
            $this->assertContains(
                $translated,
                $codes,
                sprintf('[%s] is translated but nothing emits it any more.', $translated),
            );
        }
    }

    #[Test]
    public function a_placeholder_in_a_translation_is_supplied_by_the_context(): void
    {
        /** @var array<string, mixed> $lines */
        $lines = require base_path('lang/en/ui.php');
        /** @var array<string, string> $issues */
        $issues = $lines['releases']['issue'] ?? [];

        $contexts = $this->contextKeysInSource();

        foreach ($issues as $code => $line) {
            preg_match_all('/:([a-z_]+)/', $line, $matches);

            foreach ($matches[1] as $placeholder) {
                // A translation asking for `:title` from an issue that never
                // carries one renders `:title` to a person. Cheap to check,
                // and invisible until somebody hits that exact validation.
                $this->assertContains(
                    $placeholder,
                    $contexts[$code] ?? [],
                    sprintf('[%s] uses :%s but never carries it in its context.', $code, $placeholder),
                );
            }
        }
    }

    #[Test]
    public function issues_are_deduplicated_by_code_and_path_rather_than_by_message(): void
    {
        $result = new ReleaseValidationResult([
            ReleaseValidationIssue::error('COVER_REQUIRED', 'cover', 'One wording.'),
            ReleaseValidationIssue::error('COVER_REQUIRED', 'cover', 'A different wording entirely.'),
            ReleaseValidationIssue::error('COVER_REQUIRED', 'other', 'Same code, different place.'),
        ]);

        // Two validators finding the same missing cover is one problem. The
        // same code about two different things is two.
        $this->assertCount(2, $result->issues);
    }

    #[Test]
    public function severity_lives_on_the_issue_so_the_two_lists_cannot_disagree(): void
    {
        $result = new ReleaseValidationResult([
            ReleaseValidationIssue::error('A', 'x', 'blocking'),
            ReleaseValidationIssue::warning('B', 'y', 'not blocking'),
        ]);

        $this->assertCount(1, $result->errors());
        $this->assertCount(1, $result->warnings());
        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function a_caller_asks_by_code_and_never_by_message(): void
    {
        $result = new ReleaseValidationResult([
            ReleaseValidationIssue::error('DISTRIBUTOR_UNREACHABLE', 'distributor', 'Some wording or other.'),
        ]);

        // The bug REL-002 was raised for. SubmitDelivery used to search the
        // message for a substring, so rewording it would have turned an outage
        // into a rejection — and a rejection is a verdict a distributor never
        // gave.
        $this->assertTrue($result->has('DISTRIBUTOR_UNREACHABLE'));
        $this->assertFalse($result->has('DISTRIBUTOR_REFUSED'));
    }

    #[Test]
    public function the_payload_carries_no_english(): void
    {
        $issue = ReleaseValidationIssue::error('COVER_REQUIRED', 'cover', 'A cover asset is required.');

        $encoded = json_encode($issue->toArray(), JSON_THROW_ON_ERROR);

        // The message is reachable for a log and absent from anything that
        // travels. That asymmetry is the whole design.
        $this->assertStringNotContainsString('A cover asset is required', $encoded);
        $this->assertSame('A cover asset is required.', $issue->message());
    }

    // -------------------------------------------------------------- fixtures

    /**
     * Every code the domain constructs, read from the source.
     *
     * Scanned rather than listed. A hand-kept list is one somebody forgets to
     * add to, and the forgetting is invisible until a person sees a raw code
     * on a screen.
     *
     * @return list<string>
     */
    private function codesInSource(): array
    {
        $codes = [];

        foreach ($this->sourceFiles() as $file) {
            preg_match_all(
                "/ReleaseValidationIssue::(?:error|warning)\(\s*'([A-Z0-9_]+)'/",
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * The context keys each code is constructed with.
     *
     * @return array<string, list<string>>
     */
    private function contextKeysInSource(): array
    {
        $contexts = [];

        foreach ($this->sourceFiles() as $file) {
            // Split on the constructor rather than matching a balanced call.
            // A non-greedy match to the first `);` stops at the *inner*
            // sprintf's closing paren and silently reports an empty context —
            // which is how this test first passed while checking nothing.
            $chunks = preg_split(
                '/ReleaseValidationIssue::(?:error|warning)\(/',
                (string) file_get_contents($file),
            ) ?: [];

            foreach (array_slice($chunks, 1) as $call) {
                $call = substr($call, 0, 900);
                if (preg_match("/'([A-Z0-9_]+)'/", $call, $code) !== 1) {
                    continue;
                }

                preg_match_all("/'([a-z_]+)'\s*=>/", $call, $keys);

                $contexts[$code[1]] = array_values(array_unique(
                    array_merge($contexts[$code[1]] ?? [], $keys[1]),
                ));
            }
        }

        return $contexts;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (['src/Releases', 'src/Distribution'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory)),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
