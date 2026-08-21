<?php

declare(strict_types=1);

namespace SaniTube\Worker\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * What versions of its tools a worker is actually running.
 *
 * Reported in the capability handshake because "the worker has FFmpeg" is a
 * weaker statement than an operator needs: a five-year-old build and a current
 * one both answer yes, and only one of them decodes what a distributor will
 * ask for.
 *
 * **Cached, because a handshake must be cheap.** Asking a binary its version is
 * a process spawn — small, but not free, and the handshake is polled. Cached
 * for as long as a binary's version can be expected not to change, which is
 * until somebody upgrades it; an hour is the compromise between a stale answer
 * and a spawn per poll.
 *
 * Never throws, and never runs through a shell: `Process` with an argument
 * array, so a configured path containing a space or a semicolon is an argument
 * rather than a command.
 */
final readonly class ToolVersions
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private Cache $cache,
        private ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    /**
     * @return array<string, string> tool name to version, absent when not found
     */
    public function all(): array
    {
        $versions = [];

        foreach ($this->configured() as $name => [$binary, $flag]) {
            $version = $this->versionOf($name, $binary, $flag);

            if ($version !== null) {
                $versions[$name] = $version;
            }
        }

        return $versions;
    }

    /**
     * The tools this worker was told to report on.
     *
     * From configuration rather than a list in this file: the boundary knows
     * nothing about generation, media processing or transcription, and a
     * hardcoded probe would be the first place that stopped being true. It also
     * lets an operator add a tool their own capability needs without touching
     * PHP.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function configured(): array
    {
        $configured = config('worker.tools');

        if (! is_array($configured)) {
            return [];
        }

        $tools = [];

        foreach ($configured as $name => $tool) {
            if (! is_string($name) || ! is_array($tool)) {
                continue;
            }

            $binary = $tool['binary'] ?? null;
            $flag = $tool['flag'] ?? null;

            if (is_string($binary) && trim($binary) !== '' && is_string($flag) && trim($flag) !== '') {
                $tools[$name] = [trim($binary), trim($flag)];
            }
        }

        return $tools;
    }

    private function versionOf(string $name, string $binary, string $flag): ?string
    {
        /** @var string|null $cached */
        $cached = $this->cache->remember(
            'sanitube.worker.tool.'.$name.'.'.md5($binary),
            self::TTL_SECONDS,
            function () use ($binary, $flag): string {
                $resolved = $this->resolve($binary);

                // An empty string rather than null: a cache that stores null
                // for "absent" re-probes on every call, which is the cost this
                // cache exists to avoid.
                return $resolved === null ? '' : ($this->probe($resolved, $flag) ?? '');
            },
        );

        return is_string($cached) && $cached !== '' ? $cached : null;
    }

    private function probe(string $binary, string $flag): ?string
    {
        try {
            $process = new Process([$binary, $flag], timeout: 5);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            // The first line, trimmed. Every one of these tools prints its
            // banner there and a paragraph of build flags afterwards, and the
            // build flags are neither useful here nor small.
            $first = strtok($process->getOutput(), "\n");

            return is_string($first) && trim($first) !== '' ? mb_substr(trim($first), 0, 200) : null;
        } catch (Throwable) {
            // Never throws. A handshake that failed because a version probe
            // did would make a working worker look unreachable.
            return null;
        }
    }

    private function resolve(string $configured): ?string
    {
        // An absolute path is used as-is: on shared hosting these are often
        // static binaries uploaded into the account, outside any PATH
        // directory. The same rule FfmpegDetector uses.
        if (str_contains($configured, '/')) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return $this->finder->find($configured);
    }
}
