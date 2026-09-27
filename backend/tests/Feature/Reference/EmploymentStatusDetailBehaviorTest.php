<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Application\Commands\CreateEmploymentStatusCategory;
use App\Modules\Reference\Application\Commands\CreateEmploymentStatusDetail;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetailBehavior;
use Illuminate\Support\Str;

/** S05 §12: temporal behavior periods — append-only, non-overlapping, current-on-date resolution. */
class EmploymentStatusDetailBehaviorTest extends ReferenceTestCase
{
    private function detail(): EmploymentStatusDetail
    {
        $category = app(CreateEmploymentStatusCategory::class)
            ->handle('cat_'.Str::lower(Str::random(10)), 'فئة', null, null);

        return app(CreateEmploymentStatusDetail::class)
            ->handle($category->getKey(), 'esd_'.Str::lower(Str::random(10)), 'قيد الخدمة', null, null);
    }

    private function payload(string $from, ?string $to = null): array
    {
        return [
            'effective_from' => $from,
            'effective_to' => $to,
            'participates_in_active_workforce' => true,
            'is_ongoing_relationship' => true,
            'is_relationship_ending' => false,
            'is_terminal' => false,
            'allows_reappointment' => null,
            'counts_in_monthly_reporting' => null,
        ];
    }

    public function test_defining_an_open_ended_period_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $detail = $this->detail();

        $response = $this->postJson(
            "/api/v1/reference/employment-status-details/{$detail->id}/behaviors",
            $this->payload('2026-01-01'),
        )->assertCreated();

        $response->assertJsonPath('effective_from', '2026-01-01')->assertJsonPath('effective_to', null);

        $entry = $this->latestAuditEntryFor('reference.employment_status_detail_behavior.period.define');
        $this->assertSame($detail->id, $entry->changes['status_detail_id']);
    }

    public function test_a_second_period_that_closes_the_first_with_no_gap_succeeds(): void
    {
        $this->actingAsReferenceManager();
        $detail = $this->detail();

        $this->postJson("/api/v1/reference/employment-status-details/{$detail->id}/behaviors", $this->payload('2026-01-01', '2026-06-01'))
            ->assertCreated();

        $this->postJson("/api/v1/reference/employment-status-details/{$detail->id}/behaviors", $this->payload('2026-06-01'))
            ->assertCreated();

        // EmploymentStatusDetailBehaviorController::index() returns a plain (non-paginated)
        // resource collection; this app disables the default 'data' envelope for those
        // (AppServiceProvider::JsonResource::withoutWrapping()) — only paginated collections
        // (LengthAwarePaginator, as index() uses on the simple reference families) still wrap.
        $this->getJson("/api/v1/reference/employment-status-details/{$detail->id}/behaviors")
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_an_overlapping_period_is_rejected_with_409(): void
    {
        $this->actingAsReferenceManager();
        $detail = $this->detail();

        $this->postJson("/api/v1/reference/employment-status-details/{$detail->id}/behaviors", $this->payload('2026-01-01', '2026-12-01'))
            ->assertCreated();

        // Starts before the first period's effective_to — genuinely overlaps [2026-01-01, 2026-12-01).
        $this->postJson("/api/v1/reference/employment-status-details/{$detail->id}/behaviors", $this->payload('2026-06-01'))
            ->assertStatus(409);
    }

    public function test_current_behavior_for_a_date_resolves_correctly_across_a_period_boundary(): void
    {
        $this->actingAsReferenceManager();
        $detail = $this->detail();

        $this->postJson("/api/v1/reference/employment-status-details/{$detail->id}/behaviors", array_merge(
            $this->payload('2026-01-01', '2026-06-01'),
            ['is_relationship_ending' => false],
        ))->assertCreated();

        $this->postJson("/api/v1/reference/employment-status-details/{$detail->id}/behaviors", array_merge(
            $this->payload('2026-06-01'),
            ['is_relationship_ending' => true, 'is_ongoing_relationship' => false],
        ))->assertCreated();

        $before = EmploymentStatusDetailBehavior::query()
            ->where('status_detail_id', $detail->id)
            ->where('effective_from', '<=', '2026-03-01')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', '2026-03-01'))
            ->first();
        $this->assertFalse($before->is_relationship_ending);

        $after = EmploymentStatusDetailBehavior::query()
            ->where('status_detail_id', $detail->id)
            ->where('effective_from', '<=', '2026-09-01')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', '2026-09-01'))
            ->first();
        $this->assertTrue($after->is_relationship_ending);
    }
}
