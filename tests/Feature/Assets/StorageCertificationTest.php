<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SaniTube\Storage\Certification\CertificationCheck;
use SaniTube\Storage\Certification\CertificationOutcome;
use SaniTube\Storage\Certification\CertificationReport;
use SaniTube\Storage\Certification\CertifyStorage;
use SaniTube\Storage\Providers\FilesystemStorageProvider;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * STO-006. Proving a provider can do what production asks, not what a probe asks.
 *
 * `sanitube:storage:check` writes an object, reads it back and deletes it. That
 * is the right question for a health screen and the wrong one to certify a
 * deployment on, because three operations the platform depends on are not among
 * them — and each fails on its own, for its own reason:
 *
 *   - **`move`.** Every completed upload is promoted from its staging key to
 *     its canonical one, server-side. A credential with `PutObject` and no
 *     `CopyObject` passes a write/read/delete probe and loses every upload at
 *     the last step.
 *   - **A signed URL the service accepts.** Today's report says
 *     `temporary_urls: yes`, which means the provider *claims* it can sign.
 *     Whether the signature is honoured depends on endpoint addressing, clock
 *     skew and credential scope, none of which the minting call touches.
 *   - **A presigned `PUT` that takes bytes.** Signed by a different code path
 *     than the read, and the only way a two-gigabyte master gets in on a
 *     shared account.
 *
 * The HTTP layer is faked here because these tests run with no network. What is
 * *not* faked is the shape of the claim: a check passes only when the service
 * answered and the bytes match, so a fake that answers wrongly fails the test
 * exactly as a misconfigured bucket would.
 */
final class StorageCertificationTest extends TestCase
{
    // ------------------------------------------------------------- the happy path

    #[Test]
    public function a_provider_that_does_everything_is_certified(): void
    {
        $provider = new InMemoryStorageProvider;

        $this->answerSignedRequestsFrom($provider);

        $report = $this->certifier()->certify($provider);

        $this->assertTrue($report->certified(), $this->explain($report));
        $this->assertSame([], $report->failures());

        foreach (['write', 'read', 'checksum', 'move', 'signed_read', 'presigned_write', 'delete'] as $check) {
            $this->assertSame(
                CertificationOutcome::Passed,
                $this->outcome($report->toArray(), $check),
                sprintf('[%s] did not pass.', $check),
            );
        }
    }

    /**
     * Nothing is left behind, on the happy path or any other.
     *
     * A certification run that litters a bucket is one an operator learns not
     * to run, and the objects it writes are the ones a lifecycle rule would
     * otherwise have to clean up after somebody debugging at 2am.
     */
    #[Test]
    public function certification_leaves_no_object_behind(): void
    {
        $provider = new InMemoryStorageProvider;

        $this->answerSignedRequestsFrom($provider);

        $this->certifier()->certify($provider);

        $this->assertSame([], $provider->files(CertifyStorage::PREFIX));
    }

    #[Test]
    public function it_cleans_up_even_when_a_check_fails(): void
    {
        $provider = new InMemoryStorageProvider;

        // The service refuses every signed request. The objects written before
        // that point still have to go.
        Http::fake(fn (): mixed => Http::response('no', 403));

        $report = $this->certifier()->certify($provider);

        $this->assertFalse($report->certified());
        $this->assertSame([], $provider->files(CertifyStorage::PREFIX));
    }

    // ------------------------------------------------------- what each check catches

    /**
     * The failure a write/read/delete probe cannot see.
     */
    #[Test]
    public function a_provider_that_cannot_promote_an_object_is_not_certified(): void
    {
        $provider = new InMemoryStorageProvider;
        $provider->refuseMoves();

        $this->answerSignedRequestsFrom($provider);

        $report = $this->certifier()->certify($provider);

        $this->assertFalse($report->certified(), $this->explain($report));
        $this->assertSame(
            CertificationOutcome::Failed,
            $this->outcome($report->toArray(), 'move'),
        );

        // And the checks before it still passed, so the report says *where* it
        // broke rather than only that it did.
        $this->assertSame(CertificationOutcome::Passed, $this->outcome($report->toArray(), 'write'));
        $this->assertSame(CertificationOutcome::Passed, $this->outcome($report->toArray(), 'read'));
    }

    /**
     * A digest that disagrees with the bytes.
     *
     * The object reads back correctly, so `read` passes — this is what a
     * caching layer in front of a bucket does. It matters because the digest
     * is what `sanitube:assets:verify` compares a master against, and a
     * drifting one turns every asset in the catalogue into a false alarm.
     */
    #[Test]
    public function a_provider_whose_digest_disagrees_with_its_bytes_is_not_certified(): void
    {
        $provider = new InMemoryStorageProvider;
        $provider->reportADifferentDigest();

        $this->answerSignedRequestsFrom($provider);

        $report = $this->certifier()->certify($provider);

        $this->assertFalse($report->certified(), $this->explain($report));
        $this->assertSame(CertificationOutcome::Failed, $this->outcome($report->toArray(), 'checksum'));

        // Reading the object back still worked, which is exactly why a
        // write/read probe would have called this bucket healthy.
        $this->assertSame(CertificationOutcome::Passed, $this->outcome($report->toArray(), 'read'));
    }

    /**
     * The half of a move that a copy alone satisfies.
     *
     * A server-side move is a copy followed by a delete. A credential with
     * `CopyObject` and no `DeleteObject` promotes the object, reports success,
     * and leaves the staging copy behind — the upload works, the bucket fills
     * with a duplicate of every master, and the only symptom is the bill.
     */
    #[Test]
    public function a_move_that_leaves_the_original_behind_is_not_certified(): void
    {
        $provider = new InMemoryStorageProvider;
        $provider->leaveOriginOnMove();

        $this->answerSignedRequestsFrom($provider);

        $report = $this->certifier()->certify($provider);

        $this->assertFalse($report->certified(), $this->explain($report));
        $this->assertSame(CertificationOutcome::Failed, $this->outcome($report->toArray(), 'move'));

        // And the litter it noticed is still cleaned up.
        $this->assertSame([], $provider->files(CertifyStorage::PREFIX));
    }

    #[Test]
    public function a_signature_the_service_refuses_is_not_certified(): void
    {
        $provider = new InMemoryStorageProvider;

        Http::fake(['*' => Http::response('SignatureDoesNotMatch', 403)]);

        $report = $this->certifier()->certify($provider);

        $this->assertFalse($report->certified());
        $this->assertSame(CertificationOutcome::Failed, $this->outcome($report->toArray(), 'signed_read'));

        // Refused and answered-wrongly are two different faults with two
        // different fixes — a credential or an endpoint on one side, a bucket
        // or a proxy on the other. A report that described them identically
        // would send an operator to the wrong one.
        $this->assertStringContainsString('403', $this->detail($report, 'signed_read'));
    }

    /**
     * The subtle one: the service answers 200 and serves something else.
     *
     * A check that asserted only the status would certify a bucket that hands
     * a browser an error page with an HTTP 200 on it, which is a real thing
     * misconfigured proxies and origin rules do.
     */
    #[Test]
    public function a_signed_url_that_answers_with_the_wrong_bytes_is_not_certified(): void
    {
        $provider = new InMemoryStorageProvider;

        Http::fake(['*' => Http::response('somebody else\'s object', 200)]);

        $report = $this->certifier()->certify($provider);

        $this->assertFalse($report->certified());
        $this->assertSame(CertificationOutcome::Failed, $this->outcome($report->toArray(), 'signed_read'));

        $this->assertStringContainsString('different bytes', $this->detail($report, 'signed_read'));
        $this->assertStringNotContainsString('refused', $this->detail($report, 'signed_read'));
    }

    /**
     * And the same trap on the write side: accepted, but not where we signed
     * for. A check that stopped at the status would certify an upload path
     * that silently drops every master.
     */
    #[Test]
    public function a_presigned_upload_that_lands_nowhere_is_not_certified(): void
    {
        $provider = new InMemoryStorageProvider;

        // Reads are answered from the provider; the PUT is accepted and
        // written nowhere.
        $this->answerSignedRequestsFrom($provider, acceptUploads: false);

        $report = $this->certifier()->certify($provider);

        $this->assertFalse($report->certified());
        $this->assertSame(CertificationOutcome::Failed, $this->outcome($report->toArray(), 'presigned_write'));
    }

    // ------------------------------------------------------------------ honesty

    /**
     * A local install is certified for what it does, and told plainly what it
     * does not do.
     */
    #[Test]
    public function a_provider_without_signed_urls_skips_those_checks_rather_than_failing_them(): void
    {
        // The real local provider, not a double with a flag flipped: it cannot
        // sign and does not declare SupportsDirectUpload, which is exactly the
        // shape of a cPanel install with no object storage.
        $provider = new LocalStorageProvider(Storage::fake('certify'), 'local');

        // Nothing is faked. A local install makes no HTTP request at all, and
        // a test that had to fake one would be proving the opposite.
        Http::preventStrayRequests();

        $report = $this->certifier()->certify($provider);

        $this->assertTrue($report->certified(), $this->explain($report));
        $this->assertSame(CertificationOutcome::Skipped, $this->outcome($report->toArray(), 'signed_read'));

        // The skip carries its reason. "signed_read: skipped" with no
        // explanation is an invitation to assume it was a bug.
        $this->assertSame(CertificationOutcome::Skipped, $this->outcome($report->toArray(), 'presigned_write'));

        $this->assertCount(2, $report->skipped());

        foreach ($report->skipped() as $check) {
            // "skipped" with no explanation is an invitation to assume it was
            // a bug rather than a fact about the deployment.
            $this->assertNotNull($check->detail, sprintf('[%s] was skipped without saying why.', $check->name));
        }
    }

    /**
     * Skips are not passes.
     *
     * A report of nothing but skips is a provider that was never asked
     * anything, and calling that green is how a platform declares an
     * unconfigured install production-ready.
     */
    #[Test]
    public function a_report_of_nothing_but_skips_is_not_a_certification(): void
    {
        $report = new CertificationReport('nowhere', [
            CertificationCheck::skipped('signed_read', 'no'),
            CertificationCheck::skipped('presigned_write', 'no'),
        ]);

        $this->assertFalse($report->certified());
    }

    /**
     * The prefix is shared with the health probe on purpose: one lifecycle rule
     * on the bucket covers both, and an operator has one path to recognise
     * rather than two.
     */
    #[Test]
    public function certification_writes_under_the_same_prefix_the_health_probe_uses(): void
    {
        $probePrefix = (new ReflectionClass(FilesystemStorageProvider::class))
            ->getConstant('HEALTH_PREFIX');

        $this->assertSame($probePrefix, CertifyStorage::PREFIX);
    }

    // ---------------------------------------------------------------- the command

    #[Test]
    public function the_command_reports_certification_and_says_what_it_cannot_prove(): void
    {
        $manager = $this->manager();

        $provider = new InMemoryStorageProvider;
        $manager->register('memory', $provider);

        $this->answerSignedRequestsFrom($provider);

        $this->artisan('sanitube:storage:check', ['--certify' => true])
            ->assertSuccessful()
            // The one thing no command can prove, said out loud, because a
            // green table invites the opposite conclusion.
            ->expectsOutputToContain('CORS');
    }

    #[Test]
    public function the_command_fails_when_a_provider_is_not_certified(): void
    {
        $manager = $this->manager();

        $manager->register('memory', new InMemoryStorageProvider);

        Http::fake(['*' => Http::response('nope', 403)]);

        $this->artisan('sanitube:storage:check', ['--certify' => true])->assertFailed();
    }

    /**
     * No signed URL reaches the report, on success or failure. The signature is
     * the permission, and a report is read later and by more people than the
     * operator who ran it.
     */
    #[Test]
    public function no_signed_url_appears_in_the_output(): void
    {
        $manager = $this->manager();

        $manager->register('memory', new InMemoryStorageProvider);

        Http::fake(['*' => Http::response('nope', 403)]);

        $this->artisan('sanitube:storage:check', ['--certify' => true, '--json' => true])
            ->doesntExpectOutputToContain('signature=')
            ->doesntExpectOutputToContain('expires=')
            ->assertFailed();
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * A manager holding nothing but what a test registers on it.
     */
    private function manager(): StorageManager
    {
        $manager = new StorageManager($this->app->make(FilesystemFactory::class), [], 'memory');

        $this->swap(StorageManager::class, $manager);

        return $manager;
    }

    private function certifier(): CertifyStorage
    {
        return $this->app->make(CertifyStorage::class);
    }

    /**
     * Answer signed reads and presigned writes out of the provider itself.
     *
     * The fake stands in for the service, not for the check: a GET returns what
     * the object actually holds, and a PUT writes what it was sent. A bucket
     * that behaved differently would fail here exactly as it would in
     * production.
     */
    private function answerSignedRequestsFrom(InMemoryStorageProvider $provider, bool $acceptUploads = true): void
    {
        Http::fake(function (ClientRequest $request) use ($provider, $acceptUploads): mixed {
            $key = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');

            if ($request->method() === 'PUT') {
                if ($acceptUploads) {
                    $provider->put($key, $request->body());
                }

                return Http::response('', 200);
            }

            return $provider->exists($key)
                ? Http::response($provider->get($key), 200)
                : Http::response('NoSuchKey', 404);
        });
    }

    /**
     * @param  array{provider: string, certified: bool, checks: list<array{name: string, outcome: string, detail: string|null}>}  $report
     */
    private function outcome(array $report, string $check): CertificationOutcome
    {
        foreach ($report['checks'] as $entry) {
            if ($entry['name'] === $check) {
                return CertificationOutcome::from($entry['outcome']);
            }
        }

        $this->fail(sprintf('The report contains no [%s] check.', $check));
    }

    private function detail(CertificationReport $report, string $check): string
    {
        foreach ($report->checks as $entry) {
            if ($entry->name === $check) {
                return (string) $entry->detail;
            }
        }

        $this->fail(sprintf('The report contains no [%s] check.', $check));
    }

    private function explain(CertificationReport $report): string
    {
        $lines = [];

        foreach ($report->checks as $check) {
            $lines[] = sprintf('%s: %s %s', $check->name, $check->outcome->value, $check->detail ?? '');
        }

        return implode("\n", $lines);
    }
}
