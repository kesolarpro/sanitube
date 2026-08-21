<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Console;

use Illuminate\Console\Command;
use SaniTube\Deployment\Host\HostInspector;
use SaniTube\Deployment\Profiles\ProfileAdvisor;

/**
 * What machine is this, and which installation shape fits it?
 *
 * Read-only, like the doctor, and for the same reason: it is run on hosts the
 * operator does not fully control yet, at the moment before anything has been
 * installed. Detection is file reads and PATH lookups only — this command is
 * safe on a machine where nothing else of SaniTube would be.
 *
 * The suggestion is advice, not routing. The installer takes a profile as
 * input; this exists so the operator does not have to guess which one to give
 * it, and so a support conversation can start from `sanitube:host --json`
 * instead of twenty questions.
 */
final class HostCommand extends Command
{
    protected $signature = 'sanitube:host {--json : Output the facts and the suggestion as JSON}';

    protected $description = 'Inspect this machine and suggest an installation profile';

    public function handle(HostInspector $inspector, ProfileAdvisor $advisor): int
    {
        $facts = $inspector->inspect();
        $advice = $advisor->advise($facts);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'host' => $facts->toArray(),
                'advice' => $advice->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('SaniTube host inspection');
        $this->newLine();

        foreach ($facts->toArray() as $key => $value) {
            $this->line(sprintf('  %-22s %s', $key, $this->printable($value)));
        }

        $this->newLine();
        $this->info(sprintf('Suggested profile: %s — %s', $advice->suggestion->value, $advice->suggestion->label()));

        foreach ($advice->reasons as $reason) {
            $this->line('  why: '.$reason);
        }

        foreach ($advice->cautions as $caution) {
            $this->line(sprintf('  <comment>caution:</comment> %s', $caution));
        }

        foreach ($advice->alternatives as $alternative) {
            $this->line('  also available: '.$alternative);
        }

        return self::SUCCESS;
    }

    private function printable(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            $strings = array_map(static fn (mixed $entry): string => is_string($entry) ? $entry : '?', $value);

            return $strings === [] ? '—' : implode(', ', $strings);
        }

        return is_scalar($value) ? (string) $value : '?';
    }
}
