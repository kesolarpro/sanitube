<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Foundation\Database\SchemaPortabilityInspector;
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
 * a table built to violate it.
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
    public function no_domain_table_relies_on_a_column_level_collation(): void
    {
        // A per-column collation is a portability trap: the name that works on
        // one engine may not exist on the other, and the failure appears at
        // migrate time on someone's shared hosting.
        $offenders = $this->inspector()->columnLevelCollations($this->applicationTables());

        $this->assertSame([], $offenders, 'Column-level collations found: '.implode('; ', $offenders));
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
        $name = str_repeat('a', 65);

        Schema::create('guardrail_probe', function (Blueprint $table) use ($name): void {
            $table->id();
            $table->string('value', 32);
            $table->index('value', $name);
        });

        $offenders = $this->inspector()->overlongIdentifiers(['guardrail_probe']);

        $this->assertCount(1, $offenders);
        $this->assertStringContainsString('65 chars', $offenders[0]);
    }

    #[Test]
    public function an_index_name_of_exactly_sixty_four_characters_is_accepted(): void
    {
        // The boundary matters: rejecting a legal name would send someone
        // renaming indexes for no reason.
        Schema::create('guardrail_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('value', 32);
            $table->index('value', str_repeat('a', 64));
        });

        $this->assertSame([], $this->inspector()->overlongIdentifiers(['guardrail_probe']));
    }

    #[Test]
    public function it_detects_an_index_key_too_large_for_utf8mb4(): void
    {
        // Two 400-character columns are 3200 bytes once every character costs
        // four. SQLite indexes them happily; InnoDB refuses at 3072.
        Schema::create('guardrail_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('left_side', 400);
            $table->string('right_side', 400);
            $table->index(['left_side', 'right_side'], 'guardrail_probe_wide_idx');
        });

        $inspector = new SchemaPortabilityInspector(Schema::getFacadeRoot(), [
            'guardrail_probe' => ['left_side' => 400, 'right_side' => 400],
        ]);

        $offenders = $inspector->oversizedIndexKeys(['guardrail_probe']);

        $this->assertCount(1, $offenders);
        $this->assertStringContainsString('3200 bytes', $offenders[0]);
    }

    #[Test]
    public function an_index_key_just_under_the_limit_is_accepted(): void
    {
        Schema::create('guardrail_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('value', 768);
            $table->index('value', 'guardrail_probe_narrow_idx');
        });

        $inspector = new SchemaPortabilityInspector(Schema::getFacadeRoot(), [
            'guardrail_probe' => ['value' => 768],
        ]);

        // 768 × 4 = 3072, exactly the limit.
        $this->assertSame([], $inspector->oversizedIndexKeys(['guardrail_probe']));
    }

    #[Test]
    public function an_unknown_column_length_is_costed_at_the_maximum(): void
    {
        // The conservative fallback: with no declared length available, a
        // string column is priced as varchar(255) so the check errs towards a
        // false alarm rather than a miss.
        Schema::create('guardrail_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('a', 255);
            $table->string('b', 255);
            $table->string('c', 255);
            $table->string('d', 255);
            $table->index(['a', 'b', 'c', 'd'], 'guardrail_probe_fallback_idx');
        });

        // No declared lengths passed in at all — the inspector must still
        // notice that four string columns cannot fit.
        $offenders = (new SchemaPortabilityInspector(Schema::getFacadeRoot()))
            ->oversizedIndexKeys(['guardrail_probe']);

        $this->assertCount(1, $offenders);
    }

    // -------------------------------------------------------- helpers

    private function inspector(): SchemaPortabilityInspector
    {
        return new SchemaPortabilityInspector(Schema::getFacadeRoot(), $this->declaredColumnLengths());
    }

    /**
     * @return list<string>
     */
    private function applicationTables(): array
    {
        $tables = array_map(
            static fn (array $table): string => (string) $table['name'],
            Schema::getTables(),
        );

        return array_values(array_diff($tables, self::FRAMEWORK_TABLES, ['guardrail_probe']));
    }

    /**
     * Declared string lengths, recovered from the migration source.
     *
     * SQLite discards them — it reports `varchar` with no length — so the only
     * place the intent survives locally is the migration itself.
     *
     * @return array<string, array<string, int>>
     */
    private function declaredColumnLengths(): array
    {
        $lengths = [];

        foreach ($this->migrationFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match("/Schema::create\\('([a-z0-9_]+)'/i", $contents, $table) !== 1) {
                continue;
            }

            $name = $table[1];
            $lengths[$name] ??= [];

            if (preg_match_all("/->(?:string|char)\\('([a-z0-9_]+)'(?:\\s*,\\s*(\\d+))?\\)/i", $contents, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $lengths[$name][$match[1]] = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 255;
                }
            }

            // ->morphs('linkable') expands to a 255-character type column.
            if (preg_match_all("/->(?:nullable)?[mM]orphs\\('([a-z0-9_]+)'\\)/", $contents, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $lengths[$name][$match[1].'_type'] = 255;
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
