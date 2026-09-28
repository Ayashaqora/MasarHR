<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentCategoryPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentContractPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentJobTitlePeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentJobTitleForRelationshipAsOf;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentJobTitleException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentJobTitlePeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentJobTitlePeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Application\Commands\DeactivateJobTitle;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S22 Employment Job Title History Foundation
 * (docs/employment-job-title-history-foundation-specification.md, ADR-S22-001): the employment job
 * title stream of an Employment Relationship (either appointment type) — recording, title change
 * as temporal closure, as-of resolution, PostgreSQL integrity, the active-at-command-time reference
 * rule, relationship-end/status-triggered coherence, reappointment, separation from category /
 * contract / placement / supervisory concepts, plain hr.* RBAC, and audit. ref.job_titles has no
 * seeded content (S13 forbade fabricating it), so every test uses synthetic titles. Cross-session
 * concurrency lives in ConcurrencyTest. Mirrors the S20/S21 foundation tests.
 */
class EmploymentJobTitleHistoryFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // A. Basic
    // ---------------------------------------------------------------------

    public function test_both_contract_and_permanent_relationships_can_have_a_job_title(): void
    {
        foreach (['contract', 'permanent'] as $typeCode) {
            [, $relationship] = $this->personAndRelationship($typeCode);
            $title = $this->createSyntheticJobTitle();

            $period = $this->record($relationship, $title, '2026-02-01');

            $this->assertSame($title->id, $period->job_title_id, "{$typeCode} relationship");
            $this->assertNull($period->effective_to);
            $this->assertSame('KNOWN', $period->start_knowledge_state);
        }
    }

    public function test_a_relationship_without_a_recorded_title_is_valid_and_unresolved(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->assertSame(0, $this->periodCount($relationship), 'no title is fabricated with the relationship');
        $this->assertNull($this->asOf($relationship, '2026-01-01'));
        $this->getJson($this->url($person, $relationship))->assertOk()->assertJsonCount(0);
    }

    public function test_store_returns_201_and_index_lists_history_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $a = $this->createSyntheticJobTitle();
        $b = $this->createSyntheticJobTitle();

        $this->postJson($this->url($person, $relationship), ['job_title_id' => $a->id, 'effective_from' => '2026-01-01'])
            ->assertStatus(201)
            ->assertJsonPath('job_title_id', $a->id)
            ->assertJsonPath('effective_from', '2026-01-01')
            ->assertJsonPath('effective_to', null)
            ->assertJsonPath('start_knowledge_state', 'KNOWN');
        $this->postJson($this->url($person, $relationship), ['job_title_id' => $b->id, 'effective_from' => '2026-07-01'])
            ->assertStatus(201);

        $response = $this->getJson($this->url($person, $relationship))->assertOk();
        $this->assertCount(2, $response->json());
        $this->assertSame($b->id, $response->json('0.job_title_id'));
        $this->assertSame($a->id, $response->json('1.job_title_id'));
        $this->assertSame('2026-07-01', $response->json('1.effective_to'));
    }

    public function test_as_of_resolution_uses_history_not_the_current_title(): void
    {
        $relationship = $this->relationship();
        $a = $this->createSyntheticJobTitle();
        $b = $this->createSyntheticJobTitle();
        $this->record($relationship, $a, '2026-02-01');
        $this->record($relationship, $b, '2026-07-01');

        $this->assertNull($this->asOf($relationship, '2026-01-31'), 'UNRESOLVED before any title — never a guess');
        $this->assertSame($a->id, $this->asOf($relationship, '2026-06-30')?->job_title_id, 'a past date resolves the title held then, not today\'s');
        $this->assertSame($b->id, $this->asOf($relationship, '2026-07-01')?->job_title_id, 'half-open boundary belongs to the new title');
        $this->assertSame($b->id, $this->asOf($relationship, Carbon::parse('2030-01-01'))?->job_title_id);
    }

    // ---------------------------------------------------------------------
    // B. Temporal
    // ---------------------------------------------------------------------

    public function test_a_title_may_start_on_the_relationship_start_but_never_before(): void
    {
        $relationship = $this->relationship('2026-01-01');
        $title = $this->createSyntheticJobTitle();

        try {
            $this->record($relationship, $title, '2025-12-31');
            $this->fail('a title may never predate its relationship');
        } catch (InvalidEmploymentJobTitlePeriodDateException) {
        }

        $this->record($relationship, $title, '2026-01-01');
        $this->assertSame($title->id, $this->asOf($relationship, '2026-01-01')?->job_title_id);
    }

    public function test_a_future_title_does_not_become_current_early(): void
    {
        $relationship = $this->relationship();
        $current = $this->createSyntheticJobTitle();
        $future = $this->createSyntheticJobTitle();
        $today = Carbon::today();
        $futureFrom = $today->copy()->addMonths(4)->toDateString();

        $this->record($relationship, $current, '2026-01-01');
        $this->record($relationship, $future, $futureFrom);

        $this->assertSame($current->id, $this->asOf($relationship, $today)?->job_title_id);
        $this->assertSame($future->id, $this->asOf($relationship, $futureFrom)?->job_title_id);
    }

    public function test_a_title_change_temporally_closes_the_previous_period_and_preserves_it(): void
    {
        $relationship = $this->relationship();
        $a = $this->createSyntheticJobTitle();
        $first = $this->record($relationship, $a, '2026-01-01');
        $firstId = $first->id;

        $second = $this->record($relationship, $this->createSyntheticJobTitle(), '2026-05-01');

        $first->refresh();
        $this->assertSame($firstId, $first->id);
        $this->assertSame($a->id, $first->job_title_id, 'the historical title is never overwritten');
        $this->assertSame('2026-01-01', $first->effective_from->toDateString());
        $this->assertSame('2026-05-01', $first->effective_to->toDateString(), 'temporal closure at the change date — adjacent, no overlap');
        $this->assertSame('2026-05-01', $second->effective_from->toDateString());
        $this->assertSame(2, $this->periodCount($relationship));
    }

    public function test_a_genuine_gap_in_history_is_preserved(): void
    {
        $relationship = $this->relationship();
        $title = $this->createSyntheticJobTitle();
        // A closed period with a genuine gap after it (only representable through imported data).
        $this->insertRaw($relationship, $title, '2026-01-01', '2026-03-01');

        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-06-01');

        $this->assertSame('2026-03-01', (string) DB::table('hr.employment_job_title_periods')
            ->where('employment_relationship_id', $relationship->id)->where('effective_from', '2026-01-01')->value('effective_to'), 'the earlier end is not stretched to close the gap');
        $this->assertNull($this->asOf($relationship, '2026-04-15'), 'the gap stays UNRESOLVED');
    }

    public function test_backdated_or_same_date_changes_are_rejected_and_history_is_untouched(): void
    {
        $relationship = $this->relationship();
        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-02-01');
        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-06-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-04-01', '2026-06-01', '2026-01-01'] as $from) {
            try {
                $this->record($relationship, $this->createSyntheticJobTitle(), $from);
                $this->fail("{$from} conflicts with later history");
            } catch (InvalidEmploymentJobTitlePeriodDateException) {
            }
        }

        $this->assertSame($before, $this->snapshot($relationship));
    }

    public function test_invalid_payloads_are_rejected_with_422(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->postJson($this->url($person, $relationship), [])
            ->assertStatus(422)->assertJsonValidationErrors(['job_title_id', 'effective_from']);
        $this->postJson($this->url($person, $relationship), ['job_title_id' => 'x', 'effective_from' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors(['job_title_id', 'effective_from']);
        $this->assertSame(0, $this->periodCount($relationship));
    }

    // ---------------------------------------------------------------------
    // C. PostgreSQL integrity
    // ---------------------------------------------------------------------

    public function test_direct_overlap_is_rejected_while_adjacent_periods_are_accepted(): void
    {
        $relationship = $this->relationship();
        $title = $this->createSyntheticJobTitle();
        $this->insertRaw($relationship, $title, '2026-01-01', '2026-06-01');

        $error = $this->queryError(fn () => $this->insertRaw($relationship, $title, '2026-05-01', null));
        $this->assertTrue(Errors::isExclusionViolation($error));

        $this->insertRaw($relationship, $title, '2026-06-01', null);
        $this->assertSame(2, $this->periodCount($relationship));
    }

    public function test_invalid_intervals_and_knowledge_states_are_rejected_by_check_constraints(): void
    {
        $relationship = $this->relationship();
        $title = $this->createSyntheticJobTitle();

        foreach ([['2026-06-01', '2026-06-01', 'KNOWN'], ['2026-06-01', '2026-05-01', 'KNOWN'], ['2026-06-01', null, 'GUESSED']] as [$from, $to, $state]) {
            $error = $this->queryError(fn () => $this->insertRaw($relationship, $title, $from, $to, $state));
            $this->assertTrue(Errors::isCheckViolation($error), "[{$from}, {$to}) {$state} must be rejected");
        }

        // A snapshot-evidenced legacy title (real start unknown) is representable without fabrication.
        $this->insertRaw($relationship, $title, '2026-06-01', null, 'UNKNOWN_LEGACY');
        $this->assertSame('UNKNOWN_LEGACY', $this->asOf($relationship, '2026-07-01')?->start_knowledge_state);
        $this->assertNull($this->asOf($relationship, '2026-05-31'), 'nothing is claimed before the evidenced date');
    }

    /** CA-S22-01: the normal HR write endpoint always creates KNOWN; a client cannot choose UNKNOWN_LEGACY. */
    public function test_the_normal_api_always_records_known_and_ignores_a_client_supplied_knowledge_state(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $response = $this->postJson($this->url($person, $relationship), [
            'job_title_id' => $this->createSyntheticJobTitle()->id,
            'effective_from' => '2026-02-01',
            'start_knowledge_state' => 'UNKNOWN_LEGACY',
        ])->assertStatus(201)->assertJsonPath('start_knowledge_state', 'KNOWN');

        $this->assertSame('KNOWN', DB::table('hr.employment_job_title_periods')->where('id', $response->json('id'))->value('start_knowledge_state'));
        $this->assertSame(0, DB::table('hr.employment_job_title_periods')
            ->where('employment_relationship_id', $relationship->id)->where('start_knowledge_state', 'UNKNOWN_LEGACY')->count());
    }

    /**
     * CA-S22-01: an UNKNOWN_LEGACY row stays representable (import/legacy only); its effective_from is
     * an evidence boundary, so as-of never claims the title — or any invented earlier history —
     * before it; the list exposes the knowledge state; a later KNOWN change temporally closes it
     * while preserving its UNKNOWN_LEGACY state and boundary.
     */
    public function test_an_unknown_legacy_evidence_boundary_is_honoured_by_reads_and_later_changes(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship(); // relationship starts 2026-01-01
        $legacyTitle = $this->createSyntheticJobTitle();
        $this->insertRaw($relationship, $legacyTitle, '2026-06-01', null, 'UNKNOWN_LEGACY');

        $this->assertNull($this->asOf($relationship, '2026-01-01'), 'no title is claimed between the relationship start and the evidence boundary');
        $this->assertNull($this->asOf($relationship, '2026-05-31'));
        $atBoundary = $this->asOf($relationship, '2026-06-01');
        $this->assertSame($legacyTitle->id, $atBoundary?->job_title_id);
        $this->assertSame('UNKNOWN_LEGACY', $atBoundary?->start_knowledge_state, 'callers can tell the boundary is not a verified start');
        $this->getJson($this->url($person, $relationship))->assertOk()
            ->assertJsonPath('0.start_knowledge_state', 'UNKNOWN_LEGACY')
            ->assertJsonPath('0.effective_from', '2026-06-01');

        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-09-01');

        $legacy = EmploymentJobTitlePeriod::query()->where('employment_relationship_id', $relationship->id)->where('job_title_id', $legacyTitle->id)->firstOrFail();
        $this->assertSame('UNKNOWN_LEGACY', $legacy->start_knowledge_state);
        $this->assertSame('2026-06-01', $legacy->effective_from->toDateString(), 'the evidence boundary is not moved or backdated');
        $this->assertSame('2026-09-01', $legacy->effective_to->toDateString());
        $this->assertNull($this->asOf($relationship, '2026-03-01'), 'still no invented history before the boundary');
    }

    public function test_foreign_keys_and_restrict_delete_protect_history(): void
    {
        $relationship = $this->relationship();
        $title = $this->createSyntheticJobTitle();

        $error = $this->queryError(fn () => DB::table('hr.employment_job_title_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => (string) Str::uuid7(), 'job_title_id' => $title->id,
            'effective_from' => '2026-02-01', 'start_knowledge_state' => 'KNOWN', 'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.employment_job_title_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $relationship->id, 'job_title_id' => (string) Str::uuid7(),
            'effective_from' => '2026-02-01', 'start_knowledge_state' => 'KNOWN', 'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $this->record($relationship, $title, '2026-02-01');
        $error = $this->queryError(fn () => DB::table('ref.job_titles')->where('id', $title->id)->delete());
        $this->assertTrue(Errors::isForeignKeyViolation($error), 'a referenced job title cannot be hard-deleted');
    }

    public function test_table_shape_has_no_speculative_columns_and_nothing_is_stored_on_person_or_relationship(): void
    {
        $types = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_job_title_periods')
            ->pluck('data_type', 'column_name')->all();
        ksort($types);

        $this->assertSame([
            'created_at' => 'timestamp with time zone',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'employment_relationship_id' => 'uuid',
            'id' => 'uuid',
            'job_title_id' => 'uuid',
            'start_knowledge_state' => 'character varying',
        ], $types, 'no person_id, job_desc, supervisory, category, specialty or organizational column');

        foreach (['persons', 'employment_relationships'] as $table) {
            foreach (DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', $table)->pluck('column_name') as $column) {
                $this->assertStringNotContainsString('job', $column, "hr.{$table} carries no job-title column — current title is derived from history");
            }
        }
    }

    // ---------------------------------------------------------------------
    // D. Reference
    // ---------------------------------------------------------------------

    public function test_a_nonexistent_title_is_404_and_an_inactive_title_is_rejected(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), ['job_title_id' => (string) Str::uuid7(), 'effective_from' => '2026-02-01'])
            ->assertNotFound();
        $this->postJson($this->url($person, $relationship), ['job_title_id' => $this->createSyntheticJobTitle(active: false)->id, 'effective_from' => '2026-02-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['job_title_id']);

        $this->assertSame(0, $this->periodCount($relationship));
        $this->assertSame($auditBefore, $this->auditEntriesCount());

        $this->expectException(InvalidEmploymentJobTitleException::class);
        $this->record($relationship, $this->createSyntheticJobTitle(active: false), '2026-02-01');
    }

    public function test_a_title_deactivated_later_keeps_history_intact_and_readable(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $title = $this->createSyntheticJobTitle();
        $period = $this->record($relationship, $title, '2026-02-01');
        $before = $this->snapshot($relationship);

        app(DeactivateJobTitle::class)->handle($title, $title->version);

        $this->assertSame($before, $this->snapshot($relationship));
        $this->assertSame($title->id, $this->asOf($relationship, '2026-03-01')?->job_title_id);
        $this->getJson($this->url($person, $relationship))->assertOk()->assertJsonPath('0.id', $period->id);
        $this->postJson($this->url($person, $relationship), ['job_title_id' => $title->id, 'effective_from' => '2026-06-01'])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // E. Lifecycle
    // ---------------------------------------------------------------------

    public function test_relationship_end_closes_the_open_title_and_audit_exposes_it(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $earlier = $this->record($relationship, $this->createSyntheticJobTitle(), '2026-01-01');
        $open = $this->record($relationship, $this->createSyntheticJobTitle(), '2026-04-01');

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
            'expected_version' => $relationship->version, 'effective_to' => '2026-09-01', 'is_terminal' => false,
        ])->assertOk();

        $this->assertSame('2026-09-01', $open->refresh()->effective_to->toDateString());
        $this->assertSame('2026-04-01', $earlier->refresh()->effective_to->toDateString());
        $this->assertTitlesWithinRelationship($relationship->refresh());
        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');
        $this->assertTrue($entry->metadata['employment_job_title_period_closed_as_consequence'] ?? false);
    }

    public function test_a_title_that_already_ended_is_not_extended_by_the_relationship_end(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->insertRaw($relationship, $this->createSyntheticJobTitle(), '2026-02-01', '2026-04-01');

        $this->end($person, $relationship, '2026-09-01');

        $this->assertSame('2026-04-01', (string) DB::table('hr.employment_job_title_periods')->where('employment_relationship_id', $relationship->id)->value('effective_to'));
        $this->assertArrayNotHasKey('employment_job_title_period_closed_as_consequence', $this->latestAuditEntryFor('hr.employment_relationship.end')?->metadata ?? []);
    }

    public function test_a_relationship_end_before_a_future_title_is_rejected_atomically(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-02-01');
        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-10-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-09-01', '2026-10-01'] as $endDate) {
            $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
                'expected_version' => $relationship->version, 'effective_to' => $endDate, 'is_terminal' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        }

        $this->assertSame($before, $this->snapshot($relationship));
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_a_closed_imported_title_beyond_the_end_date_rejects_the_end(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->insertRaw($relationship, $this->createSyntheticJobTitle(), '2026-02-01', '2026-11-01');

        $this->expectException(InvalidEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-09-01', false);
    }

    public function test_an_ended_relationship_rejects_a_new_title(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->end($person, $relationship, '2026-06-01');

        $this->postJson($this->url($person, $relationship->refresh()), ['job_title_id' => $this->createSyntheticJobTitle()->id, 'effective_from' => '2026-03-01'])
            ->assertStatus(409);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-03-01');
    }

    public function test_status_triggered_termination_closes_the_title_and_the_triggering_audit_exposes_it(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, $this->createSyntheticJobTitle(), '2026-02-01');
        $auditBefore = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'resigned', 'effective_from' => '2026-10-15',
        ])->assertStatus(201);

        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());
        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence'] ?? false);
        $this->assertTrue($entry->metadata['employment_job_title_period_closed_as_consequence'] ?? false);
        $this->assertSame($auditBefore + 1, $this->auditEntriesCount(), 'one entry, no duplicate consequence event');
    }

    public function test_terminal_and_rejected_status_triggered_endings_stay_coherent(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, $this->createSyntheticJobTitle(), '2026-02-01');
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('deceased'), '2026-10-15');
        $this->assertTrue($person->refresh()->is_terminal);
        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());

        $this->actingAsHrAdministrator();
        [$p2, $r2] = $this->personAndRelationship();
        $this->record($r2, $this->createSyntheticJobTitle(), '2026-02-01');
        $this->record($r2, $this->createSyntheticJobTitle(), '2026-11-01');
        $before = $this->snapshot($r2);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$p2->id}/employment-relationships/{$r2->id}/status-periods", [
            'status_detail_code' => 'resigned', 'effective_from' => '2026-10-15',
        ])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($r2));
        $this->assertSame('NOT_APPLICABLE', $r2->refresh()->end_knowledge_state);
        $this->assertSame($auditBefore, $this->auditEntriesCount(), 'no false success audit for a rolled-back termination');
    }

    // ---------------------------------------------------------------------
    // F. Reappointment
    // ---------------------------------------------------------------------

    public function test_reappointment_does_not_inherit_the_old_title_and_can_receive_its_own(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $old] = $this->personAndRelationship();
        $title = $this->createSyntheticJobTitle();
        $oldPeriod = $this->record($old, $title, '2026-01-01');
        $this->end($person, $old, '2026-06-01');

        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-08-01');

        $this->assertSame(0, $this->periodCount($new), 'no carry-forward because the Person is the same');
        $this->assertNull($this->asOf($new, '2026-08-01'));
        $this->assertSame($old->id, $oldPeriod->refresh()->employment_relationship_id);
        $this->assertSame('2026-06-01', $oldPeriod->effective_to->toDateString());

        $this->record($new, $title, '2026-08-01');
        $this->assertSame(1, $this->periodCount($new));
        $this->assertSame(1, $this->periodCount($old));
    }

    // ---------------------------------------------------------------------
    // G. Separation
    // ---------------------------------------------------------------------

    public function test_changing_title_changes_no_category_contract_placement_or_supervisory_data(): void
    {
        [, $relationship] = $this->personAndRelationship('contract');
        $category = app(RecordEmploymentCategoryPeriod::class)->handle($relationship, $this->employmentCategory('grade_3'), '2026-01-01');
        $contract = app(RecordEmploymentContractPeriod::class)->handle($relationship, $this->createSyntheticContractType(), '2026-01-01', '2027-01-01');
        $placement = $this->recordPlacement($relationship, $this->createUnit(), '2026-01-15');
        $before = $this->otherStreams($relationship);
        $supervisoryTitlesBefore = DB::table('ref.supervisory_titles')->count();

        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-02-01');
        $this->record($relationship, $this->createSyntheticJobTitle(), '2026-05-01');

        $this->assertSame($before, $this->otherStreams($relationship), 'category, contract and placement history untouched');
        $this->assertNull($category->refresh()->effective_to);
        $this->assertSame('2027-01-01', $contract->refresh()->effective_to->toDateString());
        $this->assertNull($placement->refresh()->effective_to);
        $this->assertSame($supervisoryTitlesBefore, DB::table('ref.supervisory_titles')->count(), 'no supervisory concept is created');
    }

    // ---------------------------------------------------------------------
    // H. Security
    // ---------------------------------------------------------------------

    public function test_authentication_and_hr_permissions_are_enforced(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $payload = fn () => ['job_title_id' => $this->createSyntheticJobTitle()->id, 'effective_from' => '2026-02-01'];

        $this->getJson($this->url($person, $relationship))->assertUnauthorized();
        $this->postJson($this->url($person, $relationship), [])->assertUnauthorized();

        $this->actingAs($this->createPrincipal(), 'web');
        $this->getJson($this->url($person, $relationship))->assertForbidden();
        $this->postJson($this->url($person, $relationship), $payload())->assertForbidden();

        $this->principalWithPermissions([Perm::EMPLOYMENT_JOB_TITLE_PERIODS_VIEW]);
        $this->getJson($this->url($person, $relationship))->assertOk();
        $this->postJson($this->url($person, $relationship), $payload())->assertForbidden();

        $this->principalWithPermissions([Perm::EMPLOYMENT_JOB_TITLE_PERIODS_RECORD]);
        $this->postJson($this->url($person, $relationship), $payload())->assertStatus(201);
    }

    public function test_reference_and_other_hr_permissions_never_grant_job_title_assignment(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $payload = fn () => ['job_title_id' => $this->createSyntheticJobTitle()->id, 'effective_from' => '2026-02-01'];

        $this->principalWithPermissions(ReferencePermissionCatalog::ALL);
        $this->postJson($this->url($person, $relationship), $payload())->assertForbidden();
        $this->getJson($this->url($person, $relationship))->assertForbidden();
        $this->getJson('/api/v1/reference/job-titles')->assertOk();

        $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [
            Perm::EMPLOYMENT_JOB_TITLE_PERIODS_VIEW, Perm::EMPLOYMENT_JOB_TITLE_PERIODS_RECORD,
        ])));
        $this->postJson($this->url($person, $relationship), $payload())->assertForbidden();

        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_ownership_mismatch_is_404_and_no_patch_or_delete_route_exists(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $stranger = $this->createPersonRecord();

        $this->getJson($this->url($stranger, $relationship))->assertNotFound();
        $this->postJson($this->url($stranger, $relationship), ['job_title_id' => $this->createSyntheticJobTitle()->id, 'effective_from' => '2026-02-01'])->assertNotFound();
        $this->patchJson($this->url($person, $relationship), [])->assertStatus(405);
        $this->deleteJson($this->url($person, $relationship))->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // I. Audit
    // ---------------------------------------------------------------------

    public function test_record_and_change_audits_carry_accurate_temporal_closure_metadata_without_pii(): void
    {
        $principal = $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $a = $this->createSyntheticJobTitle();
        $b = $this->createSyntheticJobTitle();

        $first = $this->postJson($this->url($person, $relationship), ['job_title_id' => $a->id, 'effective_from' => '2026-01-01'])->assertStatus(201);
        $entry = $this->latestAuditEntryFor('hr.employment_job_title_period.record');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_employment_job_title_period', $entry->target_type);
        $this->assertSame($first->json('id'), $entry->target_id);
        $this->assertEquals(['employment_relationship_id' => $relationship->id, 'job_title_id' => $a->id, 'effective_from' => '2026-01-01'], $entry->changes);
        $this->assertEquals(['job_title_code' => $a->code], $entry->metadata);

        $this->postJson($this->url($person, $relationship), ['job_title_id' => $b->id, 'effective_from' => '2026-05-01'])->assertStatus(201);
        $entry = $this->latestAuditEntryFor('hr.employment_job_title_period.record');
        $this->assertSame($first->json('id'), $entry->metadata['previous_period_id']);
        $this->assertSame('2026-05-01', $entry->metadata['previous_period_closed_at']);
        $this->assertStringNotContainsString($person->national_id, json_encode([$entry->changes, $entry->metadata]));
    }

    public function test_a_rejected_write_leaves_no_audit_and_no_partial_closure(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $existing = $this->record($relationship, $this->createSyntheticJobTitle(), '2026-05-01');
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), ['job_title_id' => $this->createSyntheticJobTitle()->id, 'effective_from' => '2026-03-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_from']);

        $this->assertSame($auditBefore, $this->auditEntriesCount());
        $this->assertNull($existing->refresh()->effective_to);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function relationship(string $effectiveFrom = '2026-01-01'): EmploymentRelationship
    {
        return $this->createEmploymentRelationship($this->createPersonRecord(), 'permanent', null, $effectiveFrom);
    }

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function personAndRelationship(string $typeCode = 'permanent'): array
    {
        $person = $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, $typeCode)];
    }

    private function record(EmploymentRelationship $relationship, JobTitle $title, string $from): EmploymentJobTitlePeriod
    {
        return app(RecordEmploymentJobTitlePeriod::class)->handle($relationship, $title, $from);
    }

    private function asOf(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentJobTitlePeriod
    {
        return app(ResolveEmploymentJobTitleForRelationshipAsOf::class)($relationship, $date);
    }

    private function end(Person $person, EmploymentRelationship $relationship, string $effectiveTo): void
    {
        app(EndEmploymentRelationship::class)->handle($person, $relationship->refresh(), $relationship->version, $effectiveTo, false);
    }

    private function url(Person $person, EmploymentRelationship $relationship): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/employment-job-title-periods";
    }

    private function periodCount(EmploymentRelationship $relationship): int
    {
        return EmploymentJobTitlePeriod::query()->where('employment_relationship_id', $relationship->id)->count();
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(EmploymentRelationship $relationship): array
    {
        return DB::table('hr.employment_job_title_periods')
            ->where('employment_relationship_id', $relationship->id)
            ->orderBy('effective_from')
            ->get(['id', 'job_title_id', 'effective_from', 'effective_to', 'start_knowledge_state'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function otherStreams(EmploymentRelationship $relationship): array
    {
        $streams = [];
        foreach (['employment_category_periods', 'employment_contract_periods', 'organizational_placement_periods', 'employment_status_periods', 'workplace_assignment_periods', 'full_secondment_periods'] as $table) {
            $streams[$table] = DB::table("hr.{$table}")->where('employment_relationship_id', $relationship->id)
                ->orderBy('effective_from')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $streams;
    }

    private function insertRaw(EmploymentRelationship $relationship, JobTitle $title, string $from, ?string $to, string $state = 'KNOWN'): void
    {
        DB::table('hr.employment_job_title_periods')->insert([
            'id' => (string) Str::uuid7(),
            'employment_relationship_id' => $relationship->id,
            'job_title_id' => $title->id,
            'effective_from' => $from,
            'effective_to' => $to,
            'start_knowledge_state' => $state,
            'created_at' => now(),
        ]);
    }

    private function assertTitlesWithinRelationship(EmploymentRelationship $relationship): void
    {
        foreach (EmploymentJobTitlePeriod::query()->where('employment_relationship_id', $relationship->id)->get() as $period) {
            $this->assertNotNull($period->effective_to, 'no title may remain in force after the relationship ended');
            $this->assertTrue($period->effective_from->gte($relationship->effective_from));
            $this->assertTrue($period->effective_to->lte($relationship->effective_to));
        }
    }

    private function queryError(callable $work): QueryException
    {
        try {
            DB::transaction(fn () => $work());
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected a QueryException.');
    }

    private function principalWithPermissions(array $permissionCodes): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions($permissionCodes);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }
}
