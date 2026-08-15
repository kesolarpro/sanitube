<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Foundation\Database\Schema\ArraySchemaSource;
use SaniTube\Foundation\Database\Schema\LiveSchemaSource;
use SaniTube\Foundation\Database\Schema\SchemaPortabilityInspector;
use SaniTube\Foundation\Database\Schema\TableColumn;
use SaniTube\Foundation\Database\Schema\TableIndex;
use Tests\TestCase;

/**
 * Fast local checks for divergences that only a real server would otherwise
 * reveal.
 *
 * **These are guardrails, not proof.** The CI matrix — SQLite, MySQL 8.0,
 * MariaDB 10.6, MariaDB 11.4 — remains the authority on whether the schema
 * works. The point here is narrower: catch the rules that are mechanically
 * checkable in a second, on SQLite, instead of in a job three minutes later.
 *
 * Half of this file exists to answer a question the guardrails themselves
 * cannot: *do they fire?* A check that has never rejected anything is
 * indistinguishable from a check that cannot. So each rule is also run against
 * a schema built to violate it.
 *
 * Those violations are *described*, never created. The first attempt built
 * them with `Schema::create()` and all three server engines rejected the DDL
 * outright — a 65-character identifier is precisely what MySQL will not let
 * you make. The engines that enforce a rule are the engines on which its
 * violation cannot exist, so the self-verification reads from
 * {@see ArraySchemaSource} and runs identically everywhere.
 */
final class DatabasePortabilityGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Framework-owned tables. Their schema is Laravel's problem and already
     * runs on every engine in the matrix.
     *
     * @var list<string>
     */
    private const FRAMEWORK_TABLES = [
        'migrations', 'users', 'password_reset_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    // ------------------------------------------------- the real schema

    #[Test]
    public function no_identifier_in_the_schema_exceeds_the_mysql_limit(): void
    {
        $offenders = $this->inspector()->overlongIdentifiers($this->applicationTables());

        $this->assertSame(
            [],
            $offenders,
            'Identifiers exceed the MySQL/MariaDB 64-character limit. Name them explicitly in the '
                .'migration: '.implode('; ', $offenders),
        );
    }

    #[Test]
    public function no_index_key_can_overflow_innodb_under_utf8mb4(): void
    {
        $offenders = $this->inspector()->oversizedIndexKeys($this->applicationTables());

        $this->assertSame(
            [],
            $offenders,
            'Index keys may exceed InnoDB\'s 3072-byte limit under utf8mb4: '.implode('; ', $offenders),
        );
    }

    #[Test]
    public function no_column_diverges_from_the_configured_collation(): void
    {
        // A column carrying a collation the rest of the installation does not
        // is a portability trap: the name that works on one engine may not
        // exist on the other, and the failure appears at migrate or restore
        // time on someone's shared hosting.
        //
        // On SQLite there is no collation to diverge from and this is vacuous,
        // which is exactly why the rule is also proved to fire below.
        $offenders = $this->inspector()->divergentCollations($this->applicationTables());

        $this->assertSame([], $offenders, 'Divergent collations found: '.implode('; ', $offenders));
    }

    #[Test]
    public function the_configured_collation_exists_on_the_oldest_supported_mariadb(): void
    {
        // utf8mb4_0900_* is MySQL 8 only. Choosing one would make every MariaDB
        // install fail at the first CREATE TABLE — and MariaDB is what cPanel
        // ships.
        foreach (['mysql', 'mariadb'] as $connection) {
            $charset = (string) config(sprintf('database.connections.%s.charset', $connection));
            $collation = (string) config(sprintf('database.connections.%s.collation', $connection));

            $this->assertSame('utf8mb4', $charset, sprintf('[%s] must use utf8mb4.', $connection));
            $this->assertStringNotContainsString(
                '0900',
                $collation,
                sprintf('[%s] uses [%s], which exists only on MySQL 8.', $connection, $collation),
            );
        }
    }

    // ------------------------------------------- do the guardrails fire?

    #[Test]
    public function it_detects_an_index_name_that_is_one_character_too_long(): void
    {
        // 65 characters: exactly the case that reached CI. SQLite accepts it
        // without complaint, which is the whole problem.
        $offenders = $this->describing([
            'probe' => ['indexes' => [new TableIndex(str_repeat('a', 65), ['value'])]],
        ])->overlongIdentifiers(['probe']);

        $this->assertCount(1, $offenders);
        $this->assertStringContainsString('65 chars', $offenders[0]);
    }

    #[Test]
    public function an_index_name_of_exactly_sixty_four_characters_is_accepted(): void
    {
        // The boundary matters: rejecting a legal name would send someone
        // renaming indexes for no reason.
        $offenders = $this->describing([
            'probe' => ['indexes' => [new TableIndex(str_repeat('a', 64), ['value'])]],
        ])->overlongIdentifiers(['probe']);

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function it_detects_a_table_name_too_long_for_the_server_engines(): void
    {
        $table = str_repeat('t', 65);

        $offenders = $this->describing([$table => []])->overlongIdentifiers([$table]);

        $this->assertCount(1, $offenders);
        $this->assertStringContainsString('table', $offenders[0]);
    }

    #[Test]
    public function it_detects_a_foreign_key_name_too_long_for_the_server_engines(): void
    {
        $offenders = $this->describing([
            'probe' => ['foreign_keys' => [str_repeat('f', 70)]],
        ])->overlongIdentifiers(['probe']);

        $this->assertCount(1, $offenders);
        $this->assertStringContainsString('foreign key', $offenders[0]);
    }

    #[Test]
    public function it_detects_an_index_key_too_large_for_utf8mb4(): void
    {
        // Two 400-character columns are 3200 bytes once every character costs
        // four. SQLite indexes them happily; InnoDB refuses at 3072.
        $offenders = $this->describing([
            'probe' => [
                'columns' => [
                    new TableColumn('left_side', 'varchar', 400),
                    new TableColumn('right_side', 'varchar', 400),
                ],
                'indexes' => [new TableIndex('probe_wide_idx', ['left_side', 'right_side'])],
            ],
        ])->oversizedIndexKeys(['probe']);

        $this->assertCount(1, $offenders);
        $this->assertStringContainsString('3200 bytes', $offenders[0]);
    }

    #[Test]
    public function an_index_key_of_exactly_the_limit_is_accepted(): void
    {
        // 768 × 4 = 3072, exactly the limit.
        $offenders = $this->describing([
            'probe' => [
                'columns' => [new TableColumn('value', 'varchar', 768)],
                'indexes' => [new TableIndex('probe_narrow_idx', ['value'])],
            ],
        ])->oversizedIndexKeys(['probe']);

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function a_column_of_unknown_width_is_costed_at_the_maximum(): void
    {
        // The conservative fallback: with no width available — SQLite reports
        // none — a string column is priced as varchar(255) so the check errs
        // towards a false alarm rather than a miss. Four of them cannot fit.
        $offenders = $this->describing([
            'probe' => [
                'columns' => [
                    new TableColumn('a', 'varchar'),
                    new TableColumn('b', 'varchar'),
                    new TableColumn('c', 'varchar'),
                    new TableColumn('d', 'varchar'),
                ],
                'indexes' => [new TableIndex('probe_fallback_idx', ['a', 'b', 'c', 'd'])],
            ],
        ])->oversizedIndexKeys(['probe']);

        $this->assertCount(1, $offenders);
    }

    #[Test]
    public function narrow_integer_columns_do_not_trip_the_index_key_rule(): void
    {
        // A wide composite index over integers is legal and common. Costing
        // every column at 255 characters would condemn it.
        $columns = [];
        $names = [];

        foreach (range(1, 40) as $position) {
            $columns[] = new TableColumn('column_'.$position, 'bigint');
            $names[] = 'column_'.$position;
        }

        $offenders = $this->describing([
            'probe' => ['columns' => $columns, 'indexes' => [new TableIndex('probe_int_idx', $names)]],
        ])->oversizedIndexKeys(['probe']);

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function it_detects_a_column_carrying_a_collation_of_its_own(): void
    {
        // The rule that is vacuous on SQLite, shown working.
        $inspector = new SchemaPortabilityInspector(
            new ArraySchemaSource([
                'probe' => [
                    'columns' => [
                        new TableColumn('inherited', 'varchar', 191, 'utf8mb4_unicode_ci'),
                        new TableColumn('divergent', 'varchar', 191, 'utf8mb4_bin'),
                    ],
                ],
            ]),
            expectedCollation: 'utf8mb4_unicode_ci',
        );

        $offenders = $inspector->divergentCollations(['probe']);

        $this->assertCount(1, $offenders);
        $this->assertStringContainsString('divergent', $offenders[0]);
        $this->assertStringContainsString('utf8mb4_bin', $offenders[0]);
    }

    #[Test]
    public function the_collation_rule_reports_nothing_when_the_engine_has_no_collations(): void
    {
        // Inapplicable is not the same as satisfied. Saying so out loud is the
        // difference between a guardrail and a green light.
        $inspector = new SchemaPortabilityInspector(
            new ArraySchemaSource([
                'probe' => ['columns' => [new TableColumn('value', 'varchar', 191, 'utf8mb4_bin')]],
            ]),
            expectedCollation: null,
        );

        $this->assertSame([], $inspector->divergentCollations(['probe']));
    }

    // -------------------------------------------------------- helpers

    /**
     * @param  array<string, array{columns?: list<TableColumn>, indexes?: list<TableIndex>, foreign_keys?: list<string>}>  $tables
     */
    private function describing(array $tables): SchemaPortabilityInspector
    {
        return new SchemaPortabilityInspector(new ArraySchemaSource($tables));
    }

    private function inspector(): SchemaPortabilityInspector
    {
        $collation = config(sprintf('database.connections.%s.collation', DB::getDefaultConnection()));

        return new SchemaPortabilityInspector(
            new LiveSchemaSource(Schema::getFacadeRoot(), $this->declaredColumnLengths()),
            expectedCollation: is_string($collation) && $collation !== '' ? $collation : null,
        );
    }

    /**
     * @return list<string>
     */
    private function applicationTables(): array
    {
        $tables = (new LiveSchemaSource(Schema::getFacadeRoot()))->tables();

        return array_values(array_diff($tables, self::FRAMEWORK_TABLES));
    }

    /**
     * Declared string widths, recovered from the migration source.
     *
     * Only SQLite needs this: MySQL and MariaDB report `varchar(191)` and the
     * width is read straight off the column. SQLite reports `varchar` with
     * nothing in brackets, so the only place the intent survives locally is
     * the migration itself.
     *
     * @return array<string, array<string, int>>
     */
    private function declaredColumnLengths(): array
    {
        $lengths = [];

        foreach ($this->migrationFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match("/Schema::create\('([a-z0-9_]+)'/i", $contents, $table) !== 1) {
                continue;
            }

            $name = $table[1];
            $lengths[$name] ??= [];

            if (preg_match_all("/->(?:string|char)\('([a-z0-9_]+)'(?:\s*,\s*(\d+))?\)/i", $contents, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $lengths[$name][$match[1]] = isset($match[2])
                        ? (int) $match[2]
                        : SchemaPortabilityInspector::ASSUMED_STRING_LENGTH;
                }
            }

            // ->morphs('linkable') expands to a 255-character type column.
            if (preg_match_all("/->(?:nullable)?[mM]orphs\('([a-z0-9_]+)'\)/", $contents, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $lengths[$name][$match[1].'_type'] = SchemaPortabilityInspector::ASSUMED_STRING_LENGTH;
                }
            }
        }

        return $lengths;
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        return array_map(
            strval(...),
            array_merge(
                (array) glob(database_path('migrations/*.php')),
                (array) glob(base_path('src/*/Database/Migrations/*.php')),
            ),
        );
    }
}
