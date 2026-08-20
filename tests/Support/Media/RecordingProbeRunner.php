<?php

declare(strict_types=1);

namespace Tests\Support\Media;

use SaniTube\Media\Analyzers\ProbeRunner;

/**
 * A probe runner that returns captured output and remembers how it was called.
 *
 * Named rather than anonymous so a test can assert on what was passed to the
 * process — which is the only way to check that the filename reached it as a
 * separate argument instead of being spliced into a command line.
 */
final class RecordingProbeRunner extends ProbeRunner
{
    /** @var list<string> */
    public array $arguments = [];

    public ?string $binary = null;

    public function __construct(private readonly string $output) {}

    /**
     * @param  list<string>  $arguments
     */
    public function run(string $binary, array $arguments, int $timeout): string
    {
        $this->binary = $binary;
        $this->arguments = $arguments;

        return $this->output;
    }
}
