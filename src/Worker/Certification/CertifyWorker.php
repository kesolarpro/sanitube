<?php

declare(strict_types=1);

namespace SaniTube\Worker\Certification;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use SaniTube\Worker\Client\WorkerClient;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerJobFailed;
use SaniTube\Worker\WorkerProtocol;
use Throwable;

/**
 * Proving a real worker is what this installation thinks it is.
 *
 * STO-006 gave object storage `sanitube:storage:check --certify`: a command an
 * operator runs against the real service, which says what it proved and what no
 * command can prove. The worker had no equivalent. Its handshake, its refusals
 * and its containment were tested against a *faked transport* — which is the
 * right way to test them and says nothing about the machine on the other end of
 * a URL somebody typed into `.env`.
 *
 * This closes that asymmetry. It does not certify the worker itself: the ledger
 * row stays BLOCKED_EXTERNAL until somebody runs this against a running one.
 * What changes is that they now can.
 *
 * **Read-only against the worker's world.** Nothing here asks it to process a
 * master, write a file, or touch storage. It asks who it is, what it says it
 * can do, and how it behaves when asked for something it does not do — the
 * protocol, not the work. A real job round-trip is named as not covered rather
 * than approximated, because approximating it is how a report claims something
 * it did not see.
 *
 * **The token never appears.** Not in a detail, not as a prefix, not in an
 * exception this catches. {@see WorkerCheck} masks it and then removes every
 * address, so the report can be pasted into a support thread.
 */
final readonly class CertifyWorker
{
    public function __construct(
        private HttpFactory $http,
        private Cache $cache,
        private Config $config,
    ) {}

    /**
     * @return list<WorkerCheck>
     */
    public function handle(): array
    {
        $client = $this->client();

        if (! $client->isConfigured()) {
            return [WorkerCheck::skipped(
                'configured',
                'No worker URL and token are configured, so there is nothing to certify.',
            )];
        }

        $checks = [WorkerCheck::passed('configured', 'A worker URL and token are set.')];

        $identity = null;

        try {
            $identity = $client->identity(fresh: true);
        } catch (Throwable $exception) {
            $checks[] = WorkerCheck::failed('handshake', $exception->getMessage(), $this->token());
        }

        if ($identity === null) {
            // The client answers null for "no worker" and "cannot reach it"
            // alike, which is right for a screen and not enough here: this is
            // the command somebody runs to find out which.
            $checks[] = WorkerCheck::failed(
                'handshake',
                'The worker did not answer the handshake, or answered in a shape this build does not '
                    .'understand. Check the URL, the token, and that the worker is running.',
                $this->token(),
            );

            return $checks;
        }

        $checks[] = WorkerCheck::passed(
            'handshake',
            sprintf('Answered as "%s", version %s.', $identity->name, $identity->version),
        );

        // Protocol before capabilities: a worker on another wire version will
        // *look* capable and misread the first real payload. Refused here so
        // the certification says why no job will ever be sent to it.
        $checks[] = $identity->speaksOurProtocol()
            ? WorkerCheck::passed('protocol', sprintf('Speaks protocol %d, same as this Core.', $identity->protocol))
            : WorkerCheck::failed('protocol', sprintf(
                'The worker speaks protocol %d and this Core speaks %d. Jobs are refused rather than '
                    .'reinterpreted; update whichever side is behind.',
                $identity->protocol,
                WorkerProtocol::VERSION,
            ), $this->token());

        $checks[] = $this->announcedCapabilities($identity->capabilities);
        $checks[] = $this->knownButNotRunnable($identity->capabilities, $identity->registered);
        $checks[] = $this->refusesWhatItDoesNotDo($client, $identity->capabilities);
        $checks[] = $this->enforcesItsToken();

        return $checks;
    }

    /**
     * @param  list<WorkerCapability>  $announced
     */
    private function announcedCapabilities(array $announced): WorkerCheck
    {
        if ($announced === []) {
            return WorkerCheck::failed(
                'capabilities',
                'The worker announced no capabilities at all, so nothing would ever be sent to it.',
                $this->token(),
            );
        }

        return WorkerCheck::passed('capabilities', sprintf('Announces: %s.', $this->names($announced)));
    }

    /**
     * What it knows how to do, and what it can do right now.
     *
     * A worker declares both: `registered` is every handler it was built with,
     * `capabilities` is the subset whose tools are actually present. A box with
     * the transcription handler installed and no model on disk announces the
     * first and not the second, and the difference is the most useful thing in
     * this report — it is the gap between what somebody installed and what will
     * actually run.
     *
     * Reported, never failed. A worker deliberately deployed without a model is
     * a correct deployment, and a certification that called it broken would be
     * one an operator learns to ignore.
     *
     * @param  list<WorkerCapability>  $available
     * @param  list<WorkerCapability>  $registered
     */
    private function knownButNotRunnable(array $available, array $registered): WorkerCheck
    {
        $idle = array_values(array_filter(
            $registered,
            static fn (WorkerCapability $capability): bool => ! in_array($capability, $available, true),
        ));

        if ($idle === []) {
            return WorkerCheck::passed(
                'capabilities_runnable',
                'Everything it knows how to do, it can do.',
            );
        }

        return WorkerCheck::passed('capabilities_runnable', sprintf(
            'Built with %s, which it cannot run right now — the handler is there and its tools are not. '
                .'Nothing will be sent for them.',
            $this->names($idle),
        ));
    }

    /**
     * Asked for something it does not do, a worker must refuse cleanly.
     *
     * The containment claim, asked of the real thing. A worker that answered
     * *anything* to an unregistered capability would be one that runs whatever
     * it is sent, and the refusal is what makes the capability list mean
     * something rather than describe an intention.
     *
     * @param  list<WorkerCapability>  $announced
     */
    private function refusesWhatItDoesNotDo(WorkerClient $client, array $announced): WorkerCheck
    {
        $candidate = null;

        foreach (WorkerCapability::cases() as $capability) {
            if (! in_array($capability, $announced, true)) {
                $candidate = $capability;

                break;
            }
        }

        if ($candidate === null) {
            return WorkerCheck::skipped(
                'refuses_unknown_work',
                'This worker announces every capability this build knows, so there is nothing it should '
                    .'refuse. That is not a failure — there was no question to ask.',
            );
        }

        try {
            $client->run($candidate, []);
        } catch (WorkerJobFailed) {
            return WorkerCheck::passed(
                'refuses_unknown_work',
                sprintf('Refused %s, which it does not announce.', $candidate->value),
            );
        } catch (Throwable $exception) {
            return WorkerCheck::failed('refuses_unknown_work', $exception->getMessage(), $this->token());
        }

        return WorkerCheck::failed(
            'refuses_unknown_work',
            sprintf(
                'The worker accepted %s, which it does not announce. A worker that answers anything it is '
                    .'sent is one whose capability list describes an intention rather than a boundary.',
                $candidate->value,
            ),
            $this->token(),
        );
    }

    /**
     * A worker that answers without the token is not protecting anything.
     *
     * The one check here that would be meaningless against a fake. It asks the
     * real machine whether the credential is doing any work, using a token that
     * is deliberately wrong — never a truncated form of the real one, which
     * would put part of it on the wire.
     */
    private function enforcesItsToken(): WorkerCheck
    {
        $wrong = $this->client(['token' => 'certification-not-a-real-token']);

        try {
            $identity = $wrong->identity(fresh: true);
        } catch (Throwable) {
            // Refused loudly is still refused.
            return WorkerCheck::passed('token_enforced', 'A request carrying the wrong token was refused.');
        }

        if ($identity === null) {
            return WorkerCheck::passed('token_enforced', 'A request carrying the wrong token was refused.');
        }

        return WorkerCheck::failed(
            'token_enforced',
            'The worker answered a request carrying the wrong token. Anybody who can reach it can use it.',
            $this->token(),
        );
    }

    /**
     * @param  list<WorkerCapability>  $capabilities
     */
    private function names(array $capabilities): string
    {
        return implode(', ', array_map(
            static fn (WorkerCapability $capability): string => $capability->value,
            $capabilities,
        ));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(array $overrides = []): WorkerClient
    {
        /** @var array<string, mixed> $settings */
        $settings = (array) $this->config->get('worker', []);

        // A separate cache key falls out of a different token, so the wrong-token
        // probe cannot be answered from the real handshake's cached reply.
        return new WorkerClient($this->http, $this->cache, [...$settings, ...$overrides]);
    }

    private function token(): string
    {
        return (string) $this->config->get('worker.token', '');
    }
}
