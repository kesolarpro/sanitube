<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deployment guides, held to the things they must not get wrong.
 *
 * Documentation drifts from code silently, and deployment documentation drifts
 * *dangerously*: a guide that still describes a queue worker after the worker
 * stopped existing costs somebody an afternoon, and a guide that omits the
 * document root costs them their credentials.
 *
 * So these tests do not check prose. They check the handful of claims where
 * being wrong is expensive, and — for the two that are properties of the
 * platform rather than of a host — that the guides agree with the code.
 */
final class DeploymentGuidesTest extends TestCase
{
    private const GUIDES = ['cpanel.md', 'vps.md'];

    #[Test]
    public function every_deployment_guide_exists(): void
    {
        foreach (self::GUIDES as $guide) {
            $this->assertFileExists(base_path('docs/deployment/'.$guide));
        }
    }

    #[Test]
    public function every_guide_says_the_document_root_is_public(): void
    {
        // The single most consequential line in either file. With the document
        // root one level too high, `.env` — the database password, the storage
        // credentials, the application key — is served over HTTP to anyone who
        // asks for it.
        //
        // Matched as an instruction, not as a string. The first version of
        // this test looked for `public/` anywhere in the file and was
        // satisfied by `public_html`, `public/build` and
        // `storage/app/public` — so deleting the actual instruction changed
        // nothing and the mutation survived.
        foreach (self::GUIDES as $guide) {
            $this->assertMatchesRegularExpression(
                '/Point the (domain|web server).{0,40}`public\/`/i',
                $this->guide($guide),
                $guide.' does not tell anybody to point the document root at public/.',
            );

            $this->assertMatchesRegularExpression(
                '/`\.env`(?:.|\n){0,120}(served|HTTP)/i',
                $this->guide($guide),
                $guide.' does not say what getting the document root wrong exposes.',
            );
        }
    }

    #[Test]
    public function the_shared_hosting_guide_gives_the_document_root_its_own_section(): void
    {
        // Not just "the instruction appears somewhere". The install steps
        // restate it in passing, which is enough to satisfy a loose match and
        // not enough to stop somebody skimming — so the guide is required to
        // carry a section that says what going wrong here costs.
        $this->assertMatchesRegularExpression(
            '/## Document root(?:.|\n)*?Point the domain at \*\*`public\/`\*\*/',
            $this->guide('cpanel.md'),
            'The shared hosting guide has no dedicated document root section.',
        );
    }

    #[Test]
    public function every_guide_says_the_scheduler_has_to_be_installed(): void
    {
        // The step people skip, and skipping it is silent: retries never
        // retry, distribution status is never refreshed, and nothing anywhere
        // says so.
        foreach (self::GUIDES as $guide) {
            $this->assertStringContainsString(
                'schedule:run',
                $this->guide($guide),
                $guide.' does not tell anybody to install the scheduler.',
            );
        }
    }

    #[Test]
    public function every_guide_is_honest_that_it_has_not_been_run(): void
    {
        // No deployment described in either file has been performed against a
        // real host. A guide that reads as a verified procedure when it is a
        // derivation is the kind of confidence that gets somebody stuck at
        // two in the morning.
        foreach (self::GUIDES as $guide) {
            $this->assertMatchesRegularExpression(
                '/has (not )?been run against a real/i',
                $this->guide($guide),
                $guide.' does not say whether it has been verified against a real host.',
            );
        }
    }

    #[Test]
    public function the_shared_hosting_guide_promises_nothing_the_platform_requires(): void
    {
        $guide = $this->guide('cpanel.md');

        // The baseline target. Every one of these being unnecessary is a
        // design constraint the whole platform is built to, and a guide that
        // quietly assumed one would be describing a different product.
        foreach (['Redis', 'Supervisor', 'Docker', 'Root'] as $luxury) {
            $this->assertStringContainsString(
                $luxury,
                $guide,
                'The shared hosting guide does not address '.$luxury.'.',
            );
        }
    }

    #[Test]
    public function the_vps_guide_explains_why_the_worker_must_be_restarted(): void
    {
        $guide = $this->guide('vps.md');

        // `bin/deploy.sh` signals workers to exit so they pick up new code and
        // deliberately does not start them again. Without a supervisor that
        // does, the queue stops after every deploy and nobody notices until an
        // import hangs.
        //
        // Asserted against the unit file rather than the page, because the
        // prose explaining `Restart=always` also contains the words — so
        // breaking the unit file left the page still matching.
        // Bounded to the unit's own [Service] block. A pattern that merely
        // required `Restart=always` *somewhere after* `[Service]` reached
        // past the unit file into the prose explaining it — so breaking the
        // unit left the page still matching, and the mutation survived.
        $this->assertMatchesRegularExpression(
            '/\[Service\][^\[]*Restart=always[^\[]*\[Install\]/',
            $guide,
            'The systemd unit does not restart the worker, so the queue stops after every deploy.',
        );

        $this->assertMatchesRegularExpression(
            '/^ExecStart=.*queue:work/m',
            $guide,
            'The systemd unit does not run a queue worker.',
        );
    }

    #[Test]
    public function no_guide_tells_anybody_to_turn_on_debug_to_investigate(): void
    {
        // Debug mode shows stack traces, queries and environment values to
        // whoever triggers the error. The settings screen calls it out for the
        // same reason.
        foreach (self::GUIDES as $guide) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(Set|Enable|Turn on)\s+APP_DEBUG\s*=?\s*true/im',
                $this->guide($guide),
                $guide.' suggests turning on debug mode.',
            );
        }
    }

    #[Test]
    public function no_guide_describes_a_destructive_deployment_step(): void
    {
        // There is a live catalogue on the other side of a deployment. The
        // deploy script is held to this by its own test; the guides are the
        // other place somebody would learn the wrong habit.
        foreach (self::GUIDES as $guide) {
            foreach (['migrate:fresh', 'migrate:reset', 'db:wipe'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $this->guide($guide),
                    $guide.' describes '.$forbidden.' as part of running SaniTube.',
                );
            }
        }
    }

    #[Test]
    public function the_guides_point_at_the_deployment_script_rather_than_repeating_it(): void
    {
        // Two copies of a deploy procedure is one copy that goes stale. The
        // guides describe what is different about each host and defer the
        // stages themselves.
        foreach (self::GUIDES as $guide) {
            $this->assertStringContainsString('bin/deploy.sh', $this->guide($guide));
            $this->assertStringContainsString('deploying.md', $this->guide($guide));
        }
    }

    private function guide(string $name): string
    {
        return (string) file_get_contents(base_path('docs/deployment/'.$name));
    }
}
