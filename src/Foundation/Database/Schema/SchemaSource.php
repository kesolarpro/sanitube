<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Database\Schema;

/**
 * Where a schema description comes from.
 *
 * This seam exists for one reason, learned the hard way: the guardrails cannot
 * be tested against a live database. To prove that the 64-character rule fires
 * you have to show it a 65-character identifier — and MySQL, MariaDB 10.6 and
 * MariaDB 11.4 all refuse to create one. The engines that enforce the rule are
 * exactly the engines on which the violation cannot be built.
 *
 * So the inspector reads a *description* of a schema, never a connection. In
 * production that description is read off the live database
 * ({@see LiveSchemaSource}); in the self-verification tests it is written by
 * hand ({@see ArraySchemaSource}), and those tests then run identically on
 * every engine in the matrix.
 */
interface SchemaSource
{
    /**
     * @return list<string>
     */
    public function tables(): array;

    /**
     * @return list<TableIndex>
     */
    public function indexes(string $table): array;

    /**
     * @return list<TableColumn>
     */
    public function columns(string $table): array;

    /**
     * Named constraints only. Engines that do not report constraint names
     * return nothing rather than inventing one.
     *
     * @return list<string>
     */
    public function foreignKeyNames(string $table): array;
}
