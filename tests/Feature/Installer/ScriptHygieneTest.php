<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shell scripts are privileged software, and audited as such.
 *
 * DEP-016. Everything under bin/ runs with the operator's shell — often
 * root's — so the scripts are held to rules a PHP review would take for
 * granted: fail on the first error, expand no unset variable, evaluate no
 * constructed string, and never pipe a download into a shell. Held against
 * every script found, not a list: the script somebody adds next year is in
 * scope the moment it exists.
 */
final class ScriptHygieneTest extends TestCase
{
    #[Test]
    public function there_are_scripts_to_audit(): void
    {
        $this->assertNotEmpty($this->scripts(), 'bin/ has no shell scripts — this test expects at least deploy, update and bootstrap.');
        $this->assertGreaterThanOrEqual(3, count($this->scripts()));
    }

    #[Test]
    public function every_script_fails_fast_and_expands_no_unset_variable(): void
    {
        foreach ($this->scripts() as $path => $script) {
            $this->assertStringContainsString(
                'set -euo pipefail',
                $script,
                sprintf('[%s] does not set -euo pipefail; a failed step would be carried on past.', $path),
            );
        }
    }

    #[Test]
    public function no_script_evaluates_constructed_strings(): void
    {
        // Judged on code, not prose: comments quote command names in
        // backticks the way documentation does, and a hygiene rule that
        // forbade *talking* about things would only teach people not to.
        foreach ($this->scripts() as $path => $script) {
            $code = $this->withoutComments($script);

            $this->assertDoesNotMatchRegularExpression(
                '/\beval\b/',
                $code,
                sprintf('[%s] uses eval, which turns any injected value into code.', $path),
            );

            $this->assertStringNotContainsString(
                '`',
                $code,
                sprintf('[%s] uses backticks; $() nests and quotes predictably, backticks do neither.', $path),
            );
        }
    }

    private function withoutComments(string $script): string
    {
        $code = [];

        foreach (explode("\n", $script) as $line) {
            if (! str_starts_with(ltrim($line), '#')) {
                $code[] = $line;
            }
        }

        return implode("\n", $code);
    }

    #[Test]
    public function no_script_pipes_a_download_into_a_shell(): void
    {
        // curl|bash executes whatever the network answered, partial downloads
        // included. The bootstrap's Composer step is the model: download to a
        // file, verify the publisher's checksum, only then let PHP run it.
        foreach ($this->scripts() as $path => $script) {
            $this->assertDoesNotMatchRegularExpression(
                '/(curl|wget)[^\n]*\|\s*(ba)?sh/',
                $script,
                sprintf('[%s] pipes a download into a shell.', $path),
            );
        }
    }

    #[Test]
    public function the_bootstrap_verifies_what_it_downloads(): void
    {
        $script = $this->scripts()[base_path('bin/bootstrap.sh')];

        $this->assertStringContainsString('sha384', $script, 'The Composer installer must be checksum-verified.');
        $this->assertStringContainsString('checksum mismatch', $script);
        $this->assertStringContainsString('rm -f composer-setup.php', $script, 'The refused installer must not be left on disk.');
    }

    #[Test]
    public function the_bootstrap_never_installs_without_being_asked(): void
    {
        $script = $this->scripts()[base_path('bin/bootstrap.sh')];

        $this->assertStringContainsString('--yes-install-packages', $script);
        $this->assertStringContainsString('Nothing was installed', $script);
        // Detection first, action second, and only behind the explicit flag
        // AND root AND a recognised package manager — all three named.
        $this->assertMatchesRegularExpression('/YES_INSTALL.*IS_ROOT.*PM/s', $script);
    }

    #[Test]
    public function every_script_is_executable_with_a_bash_shebang(): void
    {
        foreach (array_keys($this->scripts()) as $path) {
            $this->assertTrue(is_executable($path), sprintf('[%s] is not executable.', $path));
            $this->assertStringStartsWith('#!/usr/bin/env bash', (string) file_get_contents($path));
        }
    }

    /**
     * @return array<string, string> path => contents
     */
    private function scripts(): array
    {
        $scripts = [];

        foreach (glob(base_path('bin/*.sh')) ?: [] as $path) {
            $scripts[$path] = (string) file_get_contents($path);
        }

        return $scripts;
    }
}
