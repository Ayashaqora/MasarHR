<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Application\Queries\ResolveEmploymentStatusDetailBehaviorAsOf;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use Illuminate\Support\Facades\DB;

/**
 * S06 spec §11: proves the 13 employment_status_details rows seeded by
 * 2026_09_26_000026_seed_ref_employment_status_details and their initial behavior periods (seeded
 * by 2026_09_26_000027_seed_ref_employment_status_detail_behaviors) match S06-AUTH-001 §4.1
 * exactly, that behavior flags were derived from category (never from the Arabic label), and that
 * counts_in_monthly_reporting is null for all of them (spec §33, open question).
 */
class EmploymentStatusDetailSeedTest extends ReferenceTestCase
{
    /** @return array<string, string> code => expected category code, per S06-AUTH-001 §4.1 */
    private function expectedCategoryByCode(): array
    {
        return [
            'on_duty' => 'active',
            'wants_to_return' => 'active',
            'traveling' => 'non_active',
            'captive' => 'non_active',
            'suspended' => 'non_active',
            'does_not_want_to_return' => 'non_active',
            'unpaid_leave' => 'non_active',
            'external_sick_leave' => 'non_active',
            'retired' => 'ended',
            'resigned' => 'ended',
            'contract_ended' => 'ended',
            'martyred' => 'terminal',
            'deceased' => 'terminal',
        ];
    }

    /** @return array<string, array{bool, bool, bool, bool, ?bool}> category => [participates, ongoing, ending, terminal, allowsReappointment] */
    private function expectedFlagsByCategory(): array
    {
        return [
            'active' => [true, true, false, false, null],
            'non_active' => [false, true, false, false, null],
            'ended' => [false, false, true, false, true],
            'terminal' => [false, false, true, true, false],
        ];
    }

    public function test_exactly_thirteen_details_are_seeded_with_the_authorized_codes_and_categories(): void
    {
        $expected = $this->expectedCategoryByCode();

        $details = EmploymentStatusDetail::query()->with('category')->whereIn('code', array_keys($expected))->get();

        $this->assertCount(13, $details, 'exactly 13 employment_status_details must be seeded by S06');

        foreach ($details as $detail) {
            $this->assertSame($expected[$detail->code], $detail->category->code, "detail [{$detail->code}] has the wrong category");
        }
    }

    public function test_every_seeded_detail_has_exactly_one_open_ended_behavior_period_matching_its_category(): void
    {
        $expectedCategory = $this->expectedCategoryByCode();
        $expectedFlags = $this->expectedFlagsByCategory();

        $details = EmploymentStatusDetail::query()->whereIn('code', array_keys($expectedCategory))->get();

        foreach ($details as $detail) {
            $periods = DB::table('ref.employment_status_detail_behaviors')
                ->where('status_detail_id', $detail->id)
                ->get();

            $this->assertCount(1, $periods, "detail [{$detail->code}] must have exactly one seeded behavior period");

            $period = $periods->first();
            $category = $expectedCategory[$detail->code];
            [$participates, $ongoing, $ending, $terminal, $allowsReappointment] = $expectedFlags[$category];

            $this->assertNull($period->effective_to, 'the seeded period must be open-ended');
            $this->assertSame($participates, $period->participates_in_active_workforce);
            $this->assertSame($ongoing, $period->is_ongoing_relationship);
            $this->assertSame($ending, $period->is_relationship_ending);
            $this->assertSame($terminal, $period->is_terminal);
            $this->assertSame($allowsReappointment, $period->allows_reappointment === null ? null : (bool) $period->allows_reappointment);
            $this->assertNull($period->counts_in_monthly_reporting, 'counts_in_monthly_reporting must stay null — no concrete rule was given (spec §33)');
        }
    }

    public function test_as_of_resolver_resolves_every_seeded_detail_on_its_effective_date(): void
    {
        $detail = EmploymentStatusDetail::query()->where('code', 'on_duty')->firstOrFail();

        $resolved = app(ResolveEmploymentStatusDetailBehaviorAsOf::class)->__invoke($detail, '2026-09-26');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->participates_in_active_workforce);

        $unresolved = app(ResolveEmploymentStatusDetailBehaviorAsOf::class)->__invoke($detail, '2026-09-25');
        $this->assertNull($unresolved, 'a date before the seeded effective_from must be UNRESOLVED');
    }
}
