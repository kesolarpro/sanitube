<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * MySQL and MariaDB cap identifiers at 64 characters. SQLite does not.
 *
 * Laravel generates index names from the table and column names, so a pivot
 * table with three longish columns silently produces a 65-character name that
 * migrates cleanly on SQLite and fails on every production engine —
 * `composition_contributor_composition_id_contributor_id_role_unique` was
 * exactly that, and it reached CI because nothing local could see it.
 *
 * The limit is checked here, against the schema Laravel actually builds, so it
 * fails on SQLite in a second rather than on MySQL three jobs later.
 */
final class SchemaIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    /** The MySQL/MariaDB maximum identifier length. */
    private const MAX_IDENTIFIER_LENGTH = 64;

    /**
     * @return list<string>
     */
    private function domainTables(): array
    {
        return [
            'artists', 'contributors', 'compositions', 'composition_contributor',
            'tracks', 'track_artist', 'track_contributor',
            'releases', 'release_artist', 'release_tracks',
            'assets', 'asset_links',
            'external_identifiers', 'external_identifier_revocations',
        ];
    }

    #[Test]
    public function every_table_name_fits_the_identifier_limit(): void
    {
        foreach ($this->domainTables() as $table) {
            $this->assertLessThanOrEqual(
                self::MAX_IDENTIFIER_LENGTH,
                strlen($table),
                sprintf('Table name [%s] is %d characters.', $table, strlen($table)),
            );
        }
    }

    #[Test]
    public function every_generated_index_name_fits_the_identifier_limit(): void
    {
        $offenders = [];

        foreach ($this->domainTables() as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $name = (string) ($index['name'] ?? '');

                if ($name !== '' && strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
                    $offenders[] = sprintf('%s.%s (%d chars)', $table, $name, strlen($name));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Index names exceed the MySQL/MariaDB 64-character limit. Name them explicitly in the '
                .'migration: '.implode('; ', $offenders),
        );
    }

    #[Test]
    public function every_foreign_key_name_fits_the_identifier_limit(): void
    {
        $offenders = [];

        foreach ($this->domainTables() as $table) {
            try {
                $foreignKeys = Schema::getForeignKeys($table);
            } catch (Throwable) {
                // Not every driver reports constraint names; the engines that
                // enforce the limit all do.
                continue;
            }

            foreach ($foreignKeys as $foreignKey) {
                $name = (string) ($foreignKey['name'] ?? '');

                if ($name !== '' && strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
                    $offenders[] = sprintf('%s.%s (%d chars)', $table, $name, strlen($name));
                }
            }
        }

        $this->assertSame([], $offenders, 'Foreign key names exceed the limit: '.implode('; ', $offenders));
    }
}
