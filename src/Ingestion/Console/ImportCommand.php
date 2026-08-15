<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Console;

use Illuminate\Console\Command;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Exceptions\ManifestException;
use SaniTube\Ingestion\Manifest\Manifest;
use SaniTube\Ingestion\Manifest\ManifestParser;
use SaniTube\Ingestion\Manifest\ManifestRowError;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Ingestion\Sources\SourceReaderFactory;

/**
 * Starts an import without a browser.
 *
 * This is the entry point the initial catalogue actually needs. Nine hundred
 * files take hours to fetch, hash, verify and analyse; a person who has to
 * keep a tab open for that will close it, and an import that depends on a tab
 * staying open is an import that half-runs. Everything here does is create the
 * batch and queue the work — the queue does the rest, and it survives the
 * terminal being closed, the worker being killed and the machine rebooting.
 *
 * `--dry-run` exists because the interesting mistakes are made before any
 * bytes move: the wrong prefix, a manifest whose reference column points at
 * filenames rather than object keys, three hundred rows that will be refused.
 * It parses, resolves and reports, and writes nothing.
 */
final class ImportCommand extends Command
{
    protected $signature = 'sanitube:import
                            {--source=CLOUD_IMPORT : Where the material comes from}
                            {--prefix= : Import every acceptable object under this prefix}
                            {--reference=* : Import these object keys explicitly}
                            {--manifest= : Path to a CSV manifest naming what to import}
                            {--provider= : Storage provider to read from; the default when omitted}
                            {--dry-run : Report what would be imported and write nothing}';

    protected $description = 'Start an ingestion batch from a prefix, a list of references or a CSV manifest';

    public function handle(
        StartIngestionBatch $starter,
        ManifestParser $parser,
        SourceReaderFactory $readers,
    ): int {
        $source = IngestionSource::tryFrom(strtoupper((string) $this->option('source')));

        if ($source === null || ! $source->isImplemented()) {
            $this->error(sprintf(
                'Cannot import from [%s]. Implemented sources: %s.',
                (string) $this->option('source'),
                implode(', ', $this->implementedSources()),
            ));

            return self::INVALID;
        }

        $prefix = $this->stringOption('prefix');
        $manifestPath = $this->stringOption('manifest');
        $provider = $this->stringOption('provider');

        /** @var list<string> $references */
        $references = array_values(array_filter(
            array_map(static fn (mixed $r): string => is_string($r) ? trim($r) : '', (array) $this->option('reference')),
            static fn (string $r): bool => $r !== '',
        ));

        $selections = array_filter([$prefix !== null, $manifestPath !== null, $references !== []]);

        if (count($selections) !== 1) {
            $this->error(
                'Name exactly one of --prefix, --reference or --manifest. None would mean importing an '
                    .'entire store; more than one leaves it ambiguous which decided what was imported.',
            );

            return self::INVALID;
        }

        $manifest = null;

        if ($manifestPath !== null) {
            try {
                $manifest = $this->readManifest($parser, $readers, $manifestPath, $source, $provider);
            } catch (ManifestException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->reportManifest($manifest);

            if ($manifest->isEmpty()) {
                $this->error('Every row of the manifest was refused. Nothing to import.');

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run') === true) {
            return $this->reportDryRun($source, $prefix, $references, $manifest);
        }

        try {
            $batch = $starter->handle(
                source: $source,
                prefix: $prefix,
                references: $references,
                provider: $provider,
                manifest: $manifest,
            );
        } catch (IngestionException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->reportBatch($batch);

        return self::SUCCESS;
    }

    private function readManifest(
        ManifestParser $parser,
        SourceReaderFactory $readers,
        string $path,
        IngestionSource $source,
        ?string $provider,
    ): Manifest {
        if (! is_file($path) || ! is_readable($path)) {
            throw ManifestException::unreadable($path);
        }

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw ManifestException::unreadable($path);
        }

        try {
            // The reader is handed in so that a reference the source would
            // refuse — one pointing into SaniTube's own managed prefixes, say
            // — is refused as a *row*, with its line number, rather than
            // taking the other eight hundred and ninety nine down with it.
            return $parser->parse($stream, $readers->for($source, $provider));
        } finally {
            fclose($stream);
        }
    }

    private function reportManifest(Manifest $manifest): void
    {
        $this->line(sprintf(
            'Manifest: %d row(s) accepted, %d refused.',
            $manifest->acceptedCount(),
            $manifest->refusedCount(),
        ));

        if ($manifest->unknownColumns !== []) {
            $this->warn(sprintf(
                'Columns SaniTube has no field for, kept as notes: %s.',
                implode(', ', $manifest->unknownColumns),
            ));
        }

        if ($manifest->errors === []) {
            return;
        }

        $this->newLine();
        $this->table(
            ['Line', 'Code', 'Reference', 'Why'],
            array_map(static fn (ManifestRowError $error): array => [
                (string) $error->line,
                $error->code,
                $error->reference ?? '—',
                $error->message,
            ], $manifest->errors),
        );
    }

    /**
     * @param  list<string>  $references
     */
    private function reportDryRun(
        IngestionSource $source,
        ?string $prefix,
        array $references,
        ?Manifest $manifest,
    ): int {
        $this->newLine();
        $this->info('Dry run — nothing was written and nothing was queued.');

        if ($manifest instanceof Manifest) {
            $this->line(sprintf('Would import %d object(s) named by the manifest:', $manifest->acceptedCount()));

            foreach (array_slice($manifest->references(), 0, 20) as $reference) {
                $this->line('  '.$reference);
            }

            $remaining = $manifest->acceptedCount() - 20;

            if ($remaining > 0) {
                // Said out loud. A truncated list that looks complete is how
                // somebody concludes a batch is smaller than it is.
                $this->line(sprintf('  … and %d more not shown.', $remaining));
            }

            return self::SUCCESS;
        }

        if ($prefix !== null) {
            // Deliberately not expanded here: expansion lists the object store,
            // and a dry run that hits the network is a dry run people stop
            // trusting to be free.
            $this->line(sprintf(
                'Would import every acceptable object under [%s] from source %s.',
                $prefix,
                $source->value,
            ));

            return self::SUCCESS;
        }

        $this->line(sprintf('Would import %d explicitly named object(s).', count($references)));

        return self::SUCCESS;
    }

    private function reportBatch(IngestionBatch $batch): void
    {
        $counts = $batch->itemCounts();

        $this->newLine();
        $this->info(sprintf('Batch %s started.', $batch->uuid));
        $this->line(sprintf('  %d item(s) queued in total.', $batch->total()));

        foreach ($counts as $status => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %-12s %d', $status, $count));
            }
        }

        $this->newLine();
        $this->line(
            'The work is queued. It continues without this terminal — run a queue worker if one is '
                .'not already running, and follow the batch from the ingestion screens.',
        );
    }

    /**
     * @return list<string>
     */
    private function implementedSources(): array
    {
        return array_values(array_map(
            static fn (IngestionSource $source): string => $source->value,
            array_filter(IngestionSource::cases(), static fn (IngestionSource $s): bool => $s->isImplemented()),
        ));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
