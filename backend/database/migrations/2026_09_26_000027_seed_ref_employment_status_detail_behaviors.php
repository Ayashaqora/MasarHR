<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds one open-ended ([effective_from, null)) behavior period for each of the 13 details seeded
 * by 2026_09_26_000026_seed_ref_employment_status_details, closing the S05 §27 gap S06-AUTH-001
 * §7/§11 requires. Behavior flags are derived MECHANICALLY from each detail's own parent
 * category's already-frozen S05 §5.4 definition — never from the Arabic label, and identical for
 * every detail sharing a category (S06 spec §11 "Behavior-flag derivation" table). This is
 * structured translation of an approved definition, not label inference (adversarial review §28
 * item 1).
 *
 * `counts_in_monthly_reporting` is left null for every row: S06-AUTH-001 gives no concrete
 * per-detail static rule (§4.2 describes person-month aggregation logic for a future reporting
 * stage, not a per-detail boolean) — recorded as an open question, spec §33.
 *
 * `effective_from` = 2026-09-26, this stage's implementation date (spec §32): S06-AUTH-001 gives
 * no historical epoch, and backdating further would be invention. These periods are authoritative
 * "from this date forward" only.
 */
return new class extends Migration
{
    private const EFFECTIVE_FROM = '2026-09-26';

    /** @return array<string, array{participates_in_active_workforce: bool, is_ongoing_relationship: bool, is_relationship_ending: bool, is_terminal: bool, allows_reappointment: ?bool}> keyed by category code */
    private function behaviorByCategory(): array
    {
        return [
            'active' => [
                'participates_in_active_workforce' => true,
                'is_ongoing_relationship' => true,
                'is_relationship_ending' => false,
                'is_terminal' => false,
                'allows_reappointment' => null,
            ],
            'non_active' => [
                'participates_in_active_workforce' => false,
                'is_ongoing_relationship' => true,
                'is_relationship_ending' => false,
                'is_terminal' => false,
                'allows_reappointment' => null,
            ],
            'ended' => [
                'participates_in_active_workforce' => false,
                'is_ongoing_relationship' => false,
                'is_relationship_ending' => true,
                'is_terminal' => false,
                'allows_reappointment' => true,
            ],
            'terminal' => [
                'participates_in_active_workforce' => false,
                'is_ongoing_relationship' => false,
                'is_relationship_ending' => true,
                'is_terminal' => true,
                'allows_reappointment' => false,
            ],
        ];
    }

    /** @return list<string> the 13 detail codes this migration seeds a period for */
    private function detailCodes(): array
    {
        return [
            'on_duty', 'wants_to_return', 'traveling', 'captive', 'suspended',
            'does_not_want_to_return', 'unpaid_leave', 'external_sick_leave',
            'retired', 'resigned', 'contract_ended', 'martyred', 'deceased',
        ];
    }

    public function up(): void
    {
        $now = now();
        $behaviorByCategory = $this->behaviorByCategory();

        $details = DB::table('ref.employment_status_details')
            ->whereIn('ref.employment_status_details.code', $this->detailCodes())
            ->join('ref.employment_status_categories', 'ref.employment_status_categories.id', '=', 'ref.employment_status_details.category_id')
            ->select('ref.employment_status_details.id as detail_id', 'ref.employment_status_details.code as detail_code', 'ref.employment_status_categories.code as category_code')
            ->get();

        if ($details->count() !== count($this->detailCodes())) {
            throw new RuntimeException('S06 seed: expected all 13 employment_status_details rows to exist before seeding behaviors.');
        }

        foreach ($details as $detail) {
            $behavior = $behaviorByCategory[$detail->category_code] ?? null;

            if ($behavior === null) {
                throw new RuntimeException("S06 seed: no behavior derivation for category [{$detail->category_code}] (detail [{$detail->detail_code}]).");
            }

            DB::table('ref.employment_status_detail_behaviors')->insert([
                'id' => (string) Str::uuid7(),
                'status_detail_id' => $detail->detail_id,
                'effective_from' => self::EFFECTIVE_FROM,
                'effective_to' => null,
                'participates_in_active_workforce' => $behavior['participates_in_active_workforce'],
                'is_ongoing_relationship' => $behavior['is_ongoing_relationship'],
                'is_relationship_ending' => $behavior['is_relationship_ending'],
                'is_terminal' => $behavior['is_terminal'],
                'allows_reappointment' => $behavior['allows_reappointment'],
                'counts_in_monthly_reporting' => null,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ref.employment_status_detail_behaviors')
            ->whereIn('status_detail_id', function ($query): void {
                $query->select('id')
                    ->from('ref.employment_status_details')
                    ->whereIn('code', $this->detailCodes());
            })
            ->where('effective_from', self::EFFECTIVE_FROM)
            ->delete();
    }
};
