<?php

namespace App\Modules\Platform\Infrastructure\Persistence\Postgres;

use InvalidArgumentException;

/**
 * Canonical PostgreSQL SQL for MasarHR business temporal periods.
 *
 * Convention (frozen in S02): a period is the half-open range [effective_from, effective_to)
 * over DATE columns. effective_from is inclusive, effective_to is exclusive, and a NULL
 * effective_to means "open-ended" (PostgreSQL treats a NULL upper bound as unbounded).
 *
 * These methods only BUILD SQL strings; they never touch the database and know no business table.
 * Later domain migrations pass the result to DB::statement(). Because released migrations must
 * never change behaviour, the SQL produced here is pinned by tests: changing it requires adding a
 * new, explicitly named method rather than editing an existing one.
 *
 * Requires the btree_gist extension (enabled by the S02 migration) for the uuid "WITH =" operand.
 */
final class TemporalConstraints
{
    /** Bounds text passed to daterange(): lower inclusive, upper exclusive. */
    public const BOUNDS = '[)';

    /**
     * The canonical range expression, e.g. daterange("effective_from", "effective_to", '[)').
     */
    public static function rangeExpression(string $from = 'effective_from', string $to = 'effective_to'): string
    {
        return sprintf('daterange(%s, %s, \'%s\')', self::identifier($from), self::identifier($to), self::BOUNDS);
    }

    /**
     * ALTER TABLE ... ADD CONSTRAINT ... EXCLUDE USING gist (<equality columns> WITH =, <range> WITH &&).
     *
     * Rows that agree on every equality column may not have overlapping periods.
     *
     * @param  non-empty-list<string>  $equalityColumns  e.g. ['owner_id']
     */
    public static function noOverlapConstraintSql(
        string $table,
        string $constraint,
        array $equalityColumns,
        string $from = 'effective_from',
        string $to = 'effective_to',
    ): string {
        if ($equalityColumns === []) {
            throw new InvalidArgumentException('At least one equality column is required.');
        }

        $elements = array_map(
            static fn (string $column): string => self::identifier($column).' WITH =',
            array_values($equalityColumns),
        );
        $elements[] = self::rangeExpression($from, $to).' WITH &&';

        return sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s EXCLUDE USING gist (%s)',
            self::qualifiedName($table),
            self::identifier($constraint),
            implode(', ', $elements),
        );
    }

    /**
     * CHECK that the period is not empty or reversed: effective_to IS NULL OR effective_to > effective_from.
     *
     * Required next to the exclusion constraint: an EMPTY range (from = to) overlaps nothing, so
     * the exclusion constraint alone would let it through. A reversed range would be rejected by
     * daterange() with a less helpful error.
     */
    public static function validPeriodCheckSql(
        string $table,
        string $constraint,
        string $from = 'effective_from',
        string $to = 'effective_to',
    ): string {
        return sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s IS NOT NULL AND (%s IS NULL OR %s > %s))',
            self::qualifiedName($table),
            self::identifier($constraint),
            self::identifier($from),
            self::identifier($to),
            self::identifier($to),
            self::identifier($from),
        );
    }

    public static function dropConstraintSql(string $table, string $constraint): string
    {
        return sprintf(
            'ALTER TABLE %s DROP CONSTRAINT %s',
            self::qualifiedName($table),
            self::identifier($constraint),
        );
    }

    /** Quotes a table name, accepting "table" or "schema.table". */
    private static function qualifiedName(string $name): string
    {
        $parts = explode('.', $name);
        if (count($parts) > 2) {
            throw new InvalidArgumentException("Invalid table name [{$name}].");
        }

        return implode('.', array_map(self::identifier(...), $parts));
    }

    /** Only lower-case snake_case identifiers are allowed, so quoting can never be subverted. */
    private static function identifier(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid SQL identifier [{$name}]; use lower-case snake_case.");
        }

        return '"'.$name.'"';
    }
}
