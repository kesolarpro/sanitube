<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use SaniTube\Installer\Services\InstallationJournal;
use SaniTube\Observability\Certification\CertificationLedger;

abstract class TestCase extends BaseTestCase
{
    /**
     * The PHP suite must run on a checkout that has never seen Node.
     *
     * `@vite` throws when `public/build/manifest.json` is absent, and that
     * directory is a build artefact — gitignored, absent from a fresh clone,
     * absent from every Composer-only CI job. Left alone, any test that renders
     * a page fails for a reason that has nothing to do with what it asserts.
     *
     * The alternative — installing Node and building the bundle in all seven
     * PHP jobs — would also mean `php artisan test` no longer works after a
     * plain `composer install`. On a platform whose deployment target is shared
     * cPanel hosting, a test suite that requires a frontend toolchain to check
     * a database invariant is the wrong default.
     *
     * So Vite is used when a real manifest is there and stubbed when it is not.
     * Keying on the artefact rather than on a flag is safe here because no
     * assertion in the suite depends on what the manifest *contains* — only on
     * the page rendering at all — so a stale bundle cannot turn a passing test
     * red. It also means the `frontend` CI job, which has just built the
     * bundle, renders the Blade template against the real thing for free.
     *
     * What this arrangement must never do is degrade quietly: a job that meant
     * to exercise the real manifest and silently fell back to the stub would
     * report coverage it does not have. That is why the CI step asserts the
     * manifest exists before running, rather than trusting this to have taken
     * the branch it expected.
     *
     * The coverage a stub cannot give — that the entry points named in the
     * template are the ones Vite actually builds — is asserted statically, with
     * no build required, by ViteEntrypointTest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(public_path('build/manifest.json'))) {
            $this->withoutVite();
        }

        // The installation journal records real runs of the real installer,
        // and several tests run the real installer. Left on the production
        // binding they would write storage/installer/journal.json into the
        // developer's checkout — state from a test, indistinguishable from
        // state from an install. Every test therefore journals under the
        // framework's testing directory; a test that wants its own journal
        // still swaps in its own path over this one.
        $this->app->bind(
            InstallationJournal::class,
            fn (): InstallationJournal => new InstallationJournal(
                storage_path('framework/testing/installer-journal'),
            ),
        );

        // Same rule, same reason, for the certification ledger: the worker
        // and storage certification tests run the real commands, and a
        // passing run *records*. Left on the production binding they would
        // write storage/framework/certifications.json into the checkout —
        // and a test's pass must never read as a real certification.
        $this->app->bind(
            CertificationLedger::class,
            fn (): CertificationLedger => new CertificationLedger(
                storage_path('framework/testing/certification-ledger'),
            ),
        );
    }
}
