<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Application\Queries\ResolveJobTitleAdministratorClassificationAsOf;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use Illuminate\Support\Str;

/**
 * S06 spec §12.3: temporal job-title → administrator-classification periods — append-only,
 * non-overlapping, current-on-date resolution.
 */
class JobTitleAdministratorClassificationTest extends ReferenceTestCase
{
    private function jobTitle(): JobTitle
    {
        return JobTitle::create([
            'code' => 'jt_'.Str::lower(Str::random(10)),
            'name_ar' => 'مسمى وظيفي',
        ]);
    }

    public function test_defining_an_open_ended_period_succeeds_and_is_audited(): void
    {
        $this->actingAsReferenceManager();
        $jobTitle = $this->jobTitle();

        $response = $this->postJson(
            "/api/v1/reference/job-titles/{$jobTitle->id}/administrator-classifications",
            ['is_administrator' => true, 'effective_from' => '2026-01-01'],
        )->assertCreated();

        $response->assertJsonPath('is_administrator', true)->assertJsonPath('effective_to', null);

        $entry = $this->latestAuditEntryFor('reference.job_title_administrator_classification.period.define');
        $this->assertSame($jobTitle->id, $entry->changes['job_title_id']);
        $this->assertTrue($entry->changes['is_administrator']);
    }

    public function test_create_is_rejected_without_manage_permission(): void
    {
        $this->actingAsReferenceViewer();
        $jobTitle = $this->jobTitle();

        $this->postJson(
            "/api/v1/reference/job-titles/{$jobTitle->id}/administrator-classifications",
            ['is_administrator' => true, 'effective_from' => '2026-01-01'],
        )->assertStatus(403);
    }

    public function test_an_overlapping_period_is_rejected_with_409(): void
    {
        $this->actingAsReferenceManager();
        $jobTitle = $this->jobTitle();

        $this->postJson("/api/v1/reference/job-titles/{$jobTitle->id}/administrator-classifications", [
            'is_administrator' => true, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-01',
        ])->assertCreated();

        $this->postJson("/api/v1/reference/job-titles/{$jobTitle->id}/administrator-classifications", [
            'is_administrator' => false, 'effective_from' => '2026-06-01',
        ])->assertStatus(409);
    }

    public function test_as_of_resolver_resolves_correctly_across_a_period_boundary_and_is_unresolved_before(): void
    {
        $this->actingAsReferenceManager();
        $jobTitle = $this->jobTitle();

        $this->postJson("/api/v1/reference/job-titles/{$jobTitle->id}/administrator-classifications", [
            'is_administrator' => false, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-01',
        ])->assertCreated();

        $this->postJson("/api/v1/reference/job-titles/{$jobTitle->id}/administrator-classifications", [
            'is_administrator' => true, 'effective_from' => '2026-06-01',
        ])->assertCreated();

        $resolver = app(ResolveJobTitleAdministratorClassificationAsOf::class);

        $this->assertFalse($resolver->__invoke($jobTitle, '2026-03-01'));
        $this->assertTrue($resolver->__invoke($jobTitle, '2026-09-01'));
        $this->assertNull($resolver->__invoke($jobTitle, '2025-12-31'));
    }
}
