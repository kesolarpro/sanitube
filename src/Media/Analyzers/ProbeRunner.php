<?php

declare(strict_types=1);

namespace SaniTube\Media\Analyzers;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Runs the probe binary and hands back what it said.
 *
 * Separated from the analyser so the parsing — which is where the bugs live —
 * can be tested against captured output on a machine with no FFmpeg at all.
 * Testing a parser by requiring the tool that produces its input means the
 * parser is only tested where the tool happens to be installed, which is not
 * where most of CI runs.
 */
class ProbeRunner
{
    /**
     * @param  list<string>  $arguments
     *
     * @throws ProcessFailedException
     */
    public function run(string $binary, array $arguments, int $timeout): string
    {
        $process = new Process([$binary, ...$arguments], timeout: $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}
