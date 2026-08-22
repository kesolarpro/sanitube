<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Observability\Certification\CertificationLedger;
use SaniTube\Observability\Certification\ProviderStandings;
use SaniTube\Storage\Certification\CertificationOutcome;
use SaniTube\Storage\Certification\CertifyStorage;
use SaniTube\Storage\CredentialRedactor;
use SaniTube\Storage\StorageManager;
use SaniTube\Ui\Http\Requests\Settings\ConnectionTestRequest;
use SaniTube\Ui\Settings\ConnectionProbe;
use SaniTube\Worker\Certification\CertifyWorker;
use SaniTube\Worker\Certification\WorkerCheckOutcome;
use Throwable;

/**
 * "Does this actually work?", asked from the dashboard.
 *
 * CFG-001. Every probe here is one that already existed as a command —
 * `sanitube:storage:check --certify`, `sanitube:worker:check`. Nothing new
 * is *proved* by this controller; what is new is that an operator no longer
 * needs SSH to ask. A second implementation of either probe would be the
 * copy that eventually disagrees with the command, and the disagreement
 * would surface as a screen saying a bucket works while the deploy gate says
 * it does not.
 *
 * **A pass here records a real certification**, in the same ledger and with
 * the same configuration fingerprint the commands use, so `sanitube:providers`
 * cannot report CERTIFIED from a button that merely lit up green.
 *
 * **No credential, no endpoint, no key ever reaches the response.** The
 * storage certification returns named checks with details already scrubbed;
 * an unexpected exception is answered with a status and nothing of its own
 * words, because a connection exception quotes the address it failed to
 * reach.
 */
final class ConnectionTestController
{
    public function __invoke(
        ConnectionTestRequest $request,
        RecordAuditEvent $audit,
    ): JsonResponse {
        $target = $request->target();

        $result = match ($target) {
            ConnectionProbe::Storage => $this->storage(),
            ConnectionProbe::Worker => $this->worker(),
        };

        // The target and the verdict, never the address and never a key. An
        // operator reading the audit log needs to know somebody tested the
        // bucket and what it said.
        $audit->record(AuditAction::SettingsChanged, context: [
            'connection_test' => $target->value,
            'status' => $result['status'],
        ]);

        return response()->json($result);
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function storage(): array
    {
        $manager = app(StorageManager::class);
        $name = (string) config('storage.default', 'local');

        try {
            $report = app(CertifyStorage::class)->certify($manager->provider($name));
        } catch (Throwable) {
            return ['status' => 'FAILED', 'checks' => []];
        }

        $checks = [];

        foreach ($report->checks as $check) {
            $checks[] = [
                'name' => $check->name,
                'outcome' => $check->outcome->value,
                // Scrubbed on the way out, not assumed clean. A storage
                // certification detail legitimately quotes the client's own
                // error, and a failed *signed read* quotes the signed URL —
                // signature and all. That is fine in a terminal an operator
                // is already trusted with and is not fine in a browser
                // response; the shared rule removes every scheme://address.
                'detail' => CredentialRedactor::scrub($check->detail),
            ];
        }

        if ($report->certified()) {
            app(CertificationLedger::class)->record('storage:'.$name, ProviderStandings::storageFingerprint($name));

            return ['status' => 'CONNECTED', 'checks' => $checks];
        }

        // Degraded, not failed, when the store answered but could not do
        // everything: a provider without presigned uploads is a real, usable
        // configuration and calling it broken would send somebody hunting a
        // fault that is a capability.
        $failed = array_filter(
            $report->checks,
            static fn (object $check): bool => $check->outcome === CertificationOutcome::Failed,
        );

        return ['status' => $failed === [] ? 'DEGRADED' : 'FAILED', 'checks' => $checks];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function worker(): array
    {
        if (trim((string) config('worker.url')) === '' || trim((string) config('worker.token')) === '') {
            return ['status' => 'NOT_CONFIGURED', 'checks' => []];
        }

        try {
            $checks = app(CertifyWorker::class)->handle();
        } catch (Throwable) {
            return ['status' => 'FAILED', 'checks' => []];
        }

        $rows = [];
        $failed = false;
        $passed = false;

        foreach ($checks as $check) {
            $rows[] = [
                'name' => $check->name,
                'outcome' => $check->outcome->value,
                // `CertifyWorker` already strips the token and the address,
                // and this passes them through the same rule again anyway:
                // one layer here costs nothing and the response is the layer
                // that reaches a screenshot.
                'detail' => CredentialRedactor::scrub($check->detail),
            ];

            $failed = $failed || $check->outcome === WorkerCheckOutcome::Failed;
            $passed = $passed || $check->outcome === WorkerCheckOutcome::Passed;
        }

        if ($failed || ! $passed) {
            return ['status' => 'FAILED', 'checks' => $rows];
        }

        app(CertificationLedger::class)->record('worker', ProviderStandings::workerFingerprint());

        return ['status' => 'CONNECTED', 'checks' => $rows];
    }
}
