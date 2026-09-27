<?php

namespace Tests\Unit\Platform;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints as Temporal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact SQL. Released migrations call these builders, so the output must never drift.
 */
class TemporalConstraintsTest extends TestCase
{
    public function test_canonical_range_expression_is_half_open(): void
    {
        $this->assertSame('[)', Temporal::BOUNDS);
        $this->assertSame(
            'daterange("effective_from", "effective_to", \'[)\')',
            Temporal::rangeExpression(),
        );
    }

    public function test_no_overlap_constraint_sql_is_pinned(): void
    {
        $this->assertSame(
            'ALTER TABLE "hr"."example" ADD CONSTRAINT "example_no_overlap" EXCLUDE USING gist '
            .'("owner_id" WITH =, daterange("effective_from", "effective_to", \'[)\') WITH &&)',
            Temporal::noOverlapConstraintSql('hr.example', 'example_no_overlap', ['owner_id']),
        );
    }

    public function test_no_overlap_constraint_supports_several_equality_columns_and_custom_period_columns(): void
    {
        $this->assertSame(
            'ALTER TABLE "example" ADD CONSTRAINT "c" EXCLUDE USING gist '
            .'("owner_id" WITH =, "kind_id" WITH =, daterange("valid_from", "valid_to", \'[)\') WITH &&)',
            Temporal::noOverlapConstraintSql('example', 'c', ['owner_id', 'kind_id'], 'valid_from', 'valid_to'),
        );
    }

    public function test_valid_period_check_sql_is_pinned(): void
    {
        $this->assertSame(
            'ALTER TABLE "hr"."example" ADD CONSTRAINT "example_valid_period" CHECK '
            .'("effective_from" IS NOT NULL AND ("effective_to" IS NULL OR "effective_to" > "effective_from"))',
            Temporal::validPeriodCheckSql('hr.example', 'example_valid_period'),
        );
    }

    public function test_drop_constraint_sql_is_pinned_and_never_cascades(): void
    {
        $sql = Temporal::dropConstraintSql('hr.example', 'example_no_overlap');

        $this->assertSame('ALTER TABLE "hr"."example" DROP CONSTRAINT "example_no_overlap"', $sql);
        $this->assertStringNotContainsStringIgnoringCase('cascade', $sql);
    }

    public function test_requires_at_least_one_equality_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Temporal::noOverlapConstraintSql('example', 'c', []);
    }

    #[DataProvider('unsafeIdentifiers')]
    public function test_rejects_identifiers_that_are_not_lower_snake_case(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        Temporal::noOverlapConstraintSql('example', 'c', [$identifier]);
    }

    /** @return array<string, array{string}> */
    public static function unsafeIdentifiers(): array
    {
        return [
            'quote injection' => ['owner_id" WITH =; DROP TABLE x; --'],
            'space' => ['owner id'],
            'upper case' => ['OwnerId'],
            'leading digit' => ['1owner'],
            'dash' => ['owner-id'],
            'empty' => [''],
        ];
    }

    public function test_rejects_a_table_name_with_too_many_parts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Temporal::dropConstraintSql('db.hr.example', 'c');
    }
}
