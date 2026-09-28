<?php

namespace Tests\Feature\Reference;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-S14-002 ("MASARHR — S14 BLOCKER RESOLUTION + RESUME AUTHORIZATION") test requirements 1–4
 * and 10: the 2026_10_04_000001_seed_ref_decision_types_transfer migration's content, exactly as
 * ADR-S14-002 supplies it — code TRANSFER, name_ar نقل, active, no duplicate possible, no
 * sub-type seeded. Requirement 5 (rollback/reapply) is covered by
 * tests/Feature/Database/MigrationLifecycleTest.php::test_s14_migrations_roll_back_and_reapply_cleanly();
 * requirements 6–9 (TransferEmployee's own validation of decision_type_id) are covered by
 * tests/Feature/HumanResources/TransferFoundationTest.php.
 */
class DecisionTypeTransferSeedTest extends ReferenceTestCase
{
    /** ADR-S14-002 requirement 1: TRANSFER exists after migration. */
    public function test_transfer_decision_type_exists(): void
    {
        $this->assertSame(1, DecisionType::query()->where('code', 'TRANSFER')->count());
    }

    /** ADR-S14-002 requirement 2: name_ar is exactly نقل. */
    public function test_transfer_decision_type_name_ar_is_exactly_the_authoritative_arabic_value(): void
    {
        $transfer = DecisionType::query()->where('code', 'TRANSFER')->firstOrFail();

        $this->assertSame('نقل', $transfer->name_ar);
        // ADR-S14-002 supplies no English gloss — S05 §8's binding precedent (no fabricated
        // translation) applies here exactly as it does to every other seed migration in this
        // codebase since (S13's employment-categories seed, S09's employment-types seed).
        $this->assertNull($transfer->name_en);
    }

    /** ADR-S14-002 requirement 3: it is active. */
    public function test_transfer_decision_type_is_active(): void
    {
        $transfer = DecisionType::query()->where('code', 'TRANSFER')->firstOrFail();

        $this->assertTrue((bool) $transfer->is_active);
        $this->assertSame(1, $transfer->version);
    }

    /**
     * ADR-S14-002 requirement 4: duplicate TRANSFER cannot be created through migration/reapply —
     * proves the mechanism (the pre-existing `decision_types_code_unique` UNIQUE constraint, S05)
     * that makes a second seed of the same code impossible, the same way the migration's own down()/
     * up() round-trip (tested separately) never produces two rows: attempting to insert a second
     * TRANSFER row directly (simulating what a mistaken re-run of the seed migration's up() would
     * attempt, bypassing the `migrations` tracking table safeguard normal reapplication relies on)
     * is rejected by PostgreSQL itself, not merely prevented by application-layer bookkeeping.
     */
    public function test_a_second_transfer_decision_type_row_is_rejected_by_the_database_itself(): void
    {
        // Wrapped in its own DB::transaction() so PostgreSQL's own "current transaction is
        // aborted" behaviour on the rejected insert is contained to a SAVEPOINT — Laravel rolls
        // back to it and re-throws, leaving this test's own outer (DatabaseTransactions) transaction
        // healthy for the assertions that follow, mirroring how every command in this codebase
        // relies on AuditedCommandExecutor's own transaction to absorb a QueryException the same way.
        $error = null;

        try {
            DB::transaction(function (): void {
                DB::table('ref.decision_types')->insert([
                    'id' => (string) Str::uuid7(),
                    'code' => 'TRANSFER',
                    'name_ar' => 'نقل',
                    'name_en' => null,
                    'is_active' => true,
                    'display_order' => 2,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'a second row with code = TRANSFER must be rejected');
        $this->assertTrue(Errors::isUniqueViolation($error));
        $this->assertSame(1, DecisionType::query()->where('code', 'TRANSFER')->count(), 'exactly one TRANSFER row must exist after the rejected attempt');
    }

    /**
     * ADR-S14-002 requirement 10 and its explicit "DO NOT invent" list: no Transfer sub-type
     * (نقل داخلي/نقل خارجي/نقل مؤقت/نقل دائم or any other) was seeded — exactly one
     * ref.decision_types row exists for the transfer concept, and it carries none of the forbidden
     * sub-type codes or Arabic labels.
     */
    public function test_no_transfer_subtype_was_seeded(): void
    {
        $forbiddenArabicLabels = ['نقل داخلي', 'نقل خارجي', 'نقل مؤقت', 'نقل دائم'];
        $forbiddenCodes = ['TRANSFER_INTERNAL', 'TRANSFER_EXTERNAL', 'TRANSFER_TEMPORARY', 'TRANSFER_PERMANENT'];

        $this->assertSame(
            0,
            DecisionType::query()->whereIn('name_ar', $forbiddenArabicLabels)->count(),
            'ADR-S14-002 explicitly forbids seeding any Transfer sub-type',
        );
        $this->assertSame(
            0,
            DecisionType::query()->whereIn('code', $forbiddenCodes)->count(),
            'ADR-S14-002 explicitly forbids seeding any Transfer sub-type',
        );

        // Exactly one row carries the transfer concept at all — code TRANSFER, nothing else
        // starting with a transfer-shaped prefix.
        $this->assertSame(1, DecisionType::query()->where('code', 'like', 'TRANSFER%')->count());
    }
}
