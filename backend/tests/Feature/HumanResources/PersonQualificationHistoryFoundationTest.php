<?php

namespace Tests\Feature\HumanResources;

use App\Modules\Audit\Domain\Category;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry;
use App\Modules\HumanResources\Application\Commands\CorrectPersonQualification;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPersonQualification;
use App\Modules\HumanResources\Application\Queries\BuildPersonPrimaryQualificationHistory;
use App\Modules\HumanResources\Application\Queries\Reporting\BuildWorkforceAnalyticsResult;
use App\Modules\HumanResources\Domain\Exceptions\NoOpQualificationCorrectionException;
use App\Modules\HumanResources\Domain\Exceptions\StaleQualificationVersionException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualificationVersion;
use App\Modules\Platform\Domain\ActorType;
use App\Modules\Platform\Domain\Source;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S48 §S48.16 acceptance matrix (docs/person-qualification-history-foundation-specification.md).
 * Real PostgreSQL, synthetic data only. The five required cross-session CONCURRENCY rows of the
 * matrix (stale write aside, which needs no second session) live in ConcurrencyTest, which already
 * has the real-second-connection machinery; everything else — correction sequencing, the database
 * triggers, evidence completeness, provenance, pagination, ownership, audit-query scoping and the
 * permission matrix — lives here.
 */
class PersonQualificationHistoryFoundationTest extends HumanResourcesTestCase
{
    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function record(Person $person, ?string $obtainedOn = null): PersonQualification
    {
        $recording = app(RecordPersonQualification::class)->handle(
            $person,
            $this->createSyntheticAcademicDegree(),
            null,
            $obtainedOn,
            $this->syntheticActorPrincipalId(),
        );

        return $recording->qualification;
    }

    private function currentVersion(PersonQualification $qualification): PersonQualificationVersion
    {
        return PersonQualificationVersion::query()
            ->where('person_qualification_id', $qualification->getKey())
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function correct(
        Person $person,
        PersonQualification $qualification,
        int $expectedVersion,
        $degree = null,
        $type = null,
        ?string $obtainedOn = null,
        string $reason = 'تصحيح بيانات اختبار',
    ) {
        return app(CorrectPersonQualification::class)->handle(
            $person, $qualification, $expectedVersion, $degree, $type, $obtainedOn, $reason, $this->syntheticActorPrincipalId(),
        );
    }

    /** Inserts one parent row plus a version row directly — simulating a backfilled (or otherwise raw) row, bypassing RecordPersonQualification and leaving no audit trail. */
    private function insertRawQualification(
        Person $person,
        ?string $degreeId,
        ?string $typeId,
        bool $isPrimary,
        ?string $createdByPrincipalId = null,
    ): PersonQualification {
        $qualification = new PersonQualification(['person_id' => $person->getKey(), 'is_primary' => $isPrimary]);
        $qualification->save();

        $version = new PersonQualificationVersion([
            'person_qualification_id' => $qualification->getKey(),
            'person_id' => $person->getKey(),
            'version_number' => 1,
            'academic_degree_id' => $degreeId,
            'qualification_type_id' => $typeId,
            'obtained_on' => null,
            'is_current' => true,
            'reason' => null,
            'created_by_principal_id' => $createdByPrincipalId,
        ]);
        $version->save();

        return $qualification->refresh();
    }

    private function versionsUrl(Person $person, PersonQualification $qualification, array $query = []): string
    {
        $url = "/api/v1/hr/persons/{$person->id}/qualifications/{$qualification->id}/versions";

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function correctionsUrl(Person $person, PersonQualification $qualification): string
    {
        return "/api/v1/hr/persons/{$person->id}/qualifications/{$qualification->id}/corrections";
    }

    private function primaryHistoryUrl(Person $person, array $query = []): string
    {
        $url = "/api/v1/hr/persons/{$person->id}/qualifications/primary-history";

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function designateUrl(Person $person, PersonQualification $qualification): string
    {
        return "/api/v1/hr/persons/{$person->id}/qualifications/{$qualification->id}/designate-primary";
    }

    private function viewerWith(array $permissions)
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($permissions));
        $this->actingAs($principal, 'web');

        return $principal;
    }

    /**
     * Records a qualification through the real HTTP endpoint rather than calling the Application
     * command directly. Audit entries (audit.audit_entries) are written ONLY by
     * AuditedCommandExecutor::run() at the HTTP/controller layer — RecordPersonQualification::handle()
     * itself never writes one — so any scenario that needs a genuine audit trail (evidence
     * completeness, AUTO_FIRST classification, created_by_principal_id provenance) must go through
     * this helper, never through record().
     */
    private function recordViaHttp(Person $person, $degree = null): PersonQualification
    {
        $degree ??= $this->createSyntheticAcademicDegree();

        $response = $this->postJson($this->versionsUrlBase($person), [
            'academic_degree_id' => $degree->getKey(),
        ])->assertCreated();

        // Single resources are never "data"-wrapped (JsonResource::withoutWrapping(), AppServiceProvider) —
        // only collections keep the wrapper. The id is a top-level key.
        return PersonQualification::query()->findOrFail($response->json('id'));
    }

    private function versionsUrlBase(Person $person): string
    {
        return "/api/v1/hr/persons/{$person->id}/qualifications";
    }

    /** Designates a qualification as Primary through the real HTTP endpoint (see recordViaHttp doc). */
    private function designateViaHttp(Person $person, PersonQualification $qualification): void
    {
        $this->postJson($this->designateUrl($person, $qualification))->assertOk();
    }

    /** A raw audit entry, for simulating a historical chain event or a literal-scoping probe. */
    private function rawAuditEntry(array $overrides = []): AuditEntry
    {
        $entry = new AuditEntry(array_merge([
            'id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'category' => Category::Mutation,
            'action' => 'hr.person_qualification.designate_primary',
            'actor_type' => ActorType::Human,
            'actor_principal_id' => $this->createPrincipal()->id,
            // audit_entries_actor_check requires actor_label IS NULL whenever actor_type = HUMAN.
            'actor_label' => null,
            'source' => Source::Http,
            'correlation_id' => (string) Str::uuid7(),
            'target_type' => 'hr_person_qualification',
            'target_id' => (string) Str::uuid7(),
            'outcome' => Outcome::Succeeded,
            'changes' => [],
            'metadata' => [],
        ], $overrides));
        $entry->save();

        return $entry;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Repeated correction / stale write / no-op
    // ------------------------------------------------------------------------------------------------------------

    public function test_repeated_correction_produces_sequential_versions_with_exactly_one_current_throughout(): void
    {
        $person = $this->createPersonRecord();
        $qualification = $this->record($person);
        $degrees = [$this->createSyntheticAcademicDegree(), $this->createSyntheticAcademicDegree(), $this->createSyntheticAcademicDegree()];

        $v = 1;
        foreach ($degrees as $degree) {
            $this->correct($person, $qualification, $v, $degree, null, null, "تصحيح رقم {$v}");
            $v++;
            $this->assertSame(1, PersonQualificationVersion::query()->where('person_qualification_id', $qualification->getKey())->where('is_current', true)->count(), 'exactly one is_current after every correction');
        }

        $versions = PersonQualificationVersion::query()->where('person_qualification_id', $qualification->getKey())->orderBy('version_number')->get();
        $this->assertSame([1, 2, 3, 4], $versions->pluck('version_number')->all());
        $this->assertTrue($versions->last()->is_current);
        $this->assertSame(1, $versions->where('is_current', true)->count());

        $this->actingAsHrAdministrator();
        $body = $this->getJson($this->versionsUrl($person, $qualification))->assertOk()->json();
        $this->assertSame([1, 2, 3, 4], collect($body['data'])->pluck('version_number')->all(), 'all four rows remain readable through the versions endpoint');
    }

    public function test_correction_with_a_stale_expected_version_is_rejected_with_409_and_writes_nothing(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $qualification = $this->record($person);
        $degree = $this->createSyntheticAcademicDegree();

        $this->postJson($this->correctionsUrl($person, $qualification), [
            'expected_version' => 1, 'academic_degree_id' => $degree->id, 'qualification_type_id' => null, 'obtained_on' => null, 'reason' => 'سبب',
        ])->assertStatus(201);

        $another = $this->createSyntheticAcademicDegree();
        $this->postJson($this->correctionsUrl($person, $qualification), [
            'expected_version' => 1, 'academic_degree_id' => $another->id, 'qualification_type_id' => null, 'obtained_on' => null, 'reason' => 'سبب آخر',
        ])->assertStatus(409);

        $this->assertSame(2, PersonQualificationVersion::query()->where('person_qualification_id', $qualification->getKey())->count(), 'the stale attempt wrote nothing');
        $this->assertSame($degree->id, $this->currentVersion($qualification)->academic_degree_id);

        $this->expectException(StaleQualificationVersionException::class);
        $this->correct($person, $qualification, 1, $another, null);
    }

    public function test_a_correction_matching_the_current_values_exactly_is_rejected_as_a_no_op(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $degree = $this->createSyntheticAcademicDegree();
        $qualification = app(RecordPersonQualification::class)->handle($person, $degree, null, '2020-01-01', $this->syntheticActorPrincipalId())->qualification;

        $response = $this->postJson($this->correctionsUrl($person, $qualification), [
            'expected_version' => 1, 'academic_degree_id' => $degree->id, 'qualification_type_id' => null, 'obtained_on' => '2020-01-01', 'reason' => 'لا تغيير',
        ])->assertStatus(422);
        $this->assertSame(1, PersonQualificationVersion::query()->where('person_qualification_id', $qualification->getKey())->count(), 'the no-op wrote nothing');

        $this->expectException(NoOpQualificationCorrectionException::class);
        $this->correct($person, $qualification, 1, $degree, null, '2020-01-01');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Database-level guarantees: deferred checks, person-match, immutability
    // ------------------------------------------------------------------------------------------------------------

    public function test_parent_table_insert_without_a_matching_version_is_rejected_at_commit_with_ma005(): void
    {
        $person = $this->createPersonRecord();

        // DB::transaction() nests as a SAVEPOINT inside this test's own ambient (DatabaseTransactions)
        // ambient transaction. SET CONSTRAINTS ALL IMMEDIATE forces the deferred trigger to evaluate
        // right here, without needing a real COMMIT; when it raises, Laravel's transaction() rolls
        // back to the savepoint, which also reverts the SET CONSTRAINTS itself (transactional), so
        // the ambient transaction is left healthy for the rest of this test and the ones after it.
        // Running the same statements bare (outside a savepoint) would instead leave the whole
        // ambient transaction permanently aborted once the check fails.
        $error = $this->databaseError(fn () => DB::transaction(function () use ($person): void {
            DB::table('hr.person_qualifications')->insert(['id' => (string) Str::uuid7(), 'person_id' => $person->id, 'created_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }));

        $this->assertTrue(Errors::isQualificationMissingCurrentVersion($error), 'a qualification with zero versions must never commit (D32, MA005)');
    }

    /**
     * Every risky statement below runs inside DB::transaction() — a SAVEPOINT nested in this
     * test's own ambient (DatabaseTransactions) transaction. Without it, the first violation would
     * leave the WHOLE ambient transaction aborted (PostgreSQL rejects every further statement
     * until a ROLLBACK), breaking every assertion and DB call after it, including in a later test
     * in the same run. $this->databaseError() (PostgresIntegrationTestCase) runs the closure and
     * returns the exception Laravel's transaction() re-throws after rolling back to the savepoint.
     */
    private function expectDatabaseError(\Closure $work): \Throwable
    {
        return $this->databaseError(fn () => DB::transaction($work));
    }

    public function test_a_version_whose_person_id_disagrees_with_its_parent_is_rejected_on_insert_and_update(): void
    {
        $person = $this->createPersonRecord();
        $other = $this->createPersonRecord();
        $qualification = $this->record($person);
        $degree = $this->createSyntheticAcademicDegree();

        $insertError = $this->expectDatabaseError(function () use ($qualification, $other, $degree): void {
            DB::table('hr.person_qualification_versions')->insert([
                'id' => (string) Str::uuid7(), 'person_qualification_id' => $qualification->getKey(), 'person_id' => $other->id,
                'version_number' => 2, 'academic_degree_id' => $degree->id, 'qualification_type_id' => null, 'is_current' => false, 'created_at' => now(),
            ]);
        });
        $this->assertTrue(Errors::isQualificationVersionPersonMismatch($insertError));

        // PostgreSQL fires same-timing BEFORE triggers in alphabetical order:
        // person_qualification_versions_immutable sorts before ..._person_match, so an UPDATE that
        // changes person_id is rejected by the immutability trigger (MA003) before the person-match
        // trigger's own UPDATE branch ever runs — the row is just as rejected either way, which is
        // the acceptance matrix's actual requirement ("rejected ... in both cases"), but the SQLSTATE
        // is MA003 here, not MA002.
        $current = $this->currentVersion($qualification);
        $updateError = $this->expectDatabaseError(function () use ($current, $other): void {
            DB::table('hr.person_qualification_versions')->where('id', $current->getKey())->update(['person_id' => $other->id]);
        });
        $this->assertTrue(Errors::isQualificationVersionImmutableUpdate($updateError));
    }

    public function test_the_parent_rows_id_and_person_id_are_immutable_once_created(): void
    {
        $person = $this->createPersonRecord();
        $other = $this->createPersonRecord();
        $qualification = $this->record($person);

        $personIdError = $this->expectDatabaseError(function () use ($qualification, $other): void {
            DB::table('hr.person_qualifications')->where('id', $qualification->getKey())->update(['person_id' => $other->id]);
        });
        $this->assertTrue(Errors::isQualificationIdentityImmutable($personIdError));

        $idError = $this->expectDatabaseError(function () use ($qualification): void {
            DB::table('hr.person_qualifications')->where('id', $qualification->getKey())->update(['id' => (string) Str::uuid7()]);
        });
        $this->assertTrue(Errors::isQualificationIdentityImmutable($idError));
    }

    public function test_version_rows_are_immutable_except_the_current_flip_and_can_never_be_deleted_even_when_backfilled(): void
    {
        $person = $this->createPersonRecord();
        $qualification = $this->record($person);
        $current = $this->currentVersion($qualification);

        // The one permitted mutation.
        DB::table('hr.person_qualification_versions')->where('id', $current->getKey())->update(['is_current' => false]);
        $this->assertFalse($current->refresh()->is_current);

        // Any other field, even restoring is_current back to true, is rejected.
        $reasonError = $this->expectDatabaseError(function () use ($current): void {
            DB::table('hr.person_qualification_versions')->where('id', $current->getKey())->update(['reason' => 'يجب أن يُرفض']);
        });
        $this->assertTrue(Errors::isQualificationVersionImmutableUpdate($reasonError));

        // DELETE is rejected unconditionally, including on a row simulating a backfilled version-1
        // (created_by_principal_id IS NULL, D36) — the backfill produces real, permanent rows, not
        // throwaway ones.
        $backfilled = $this->insertRawQualification($person, $this->createSyntheticAcademicDegree()->id, null, false);
        $backfilledVersion = $this->currentVersion($backfilled);
        $this->assertNull($backfilledVersion->created_by_principal_id);

        $deleteError = $this->expectDatabaseError(function () use ($backfilledVersion): void {
            DB::table('hr.person_qualification_versions')->where('id', $backfilledVersion->getKey())->delete();
        });
        $this->assertTrue(Errors::isQualificationVersionImmutableDelete($deleteError));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Corrected Primary — report buckets may shift, population never does (D29)
    // ------------------------------------------------------------------------------------------------------------

    public function test_correcting_the_current_primary_may_shift_report_buckets_but_never_the_overall_headcount(): void
    {
        $person = $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail('on_duty'), '2026-10-01');
        $degreeA = $this->createSyntheticAcademicDegree();
        $degreeB = $this->createSyntheticAcademicDegree();
        $qualification = app(RecordPersonQualification::class)->handle($person, $degreeA, null, null, $this->syntheticActorPrincipalId())->qualification; // auto-Primary

        $before = app(BuildWorkforceAnalyticsResult::class)('2026-11-01');
        $bucketsBefore = $before->sections['qualifications']['primary_qualification']['buckets'];
        $beforeHasA = collect($bucketsBefore)->contains(fn ($b) => ($b['academic_degree']['id'] ?? null) === $degreeA->id);
        $this->assertTrue($beforeHasA, 'the Person starts out bucketed under degree A');

        $this->correct($person, $qualification, 1, $degreeB, null, null, 'نقل إلى درجة أخرى');

        $after = app(BuildWorkforceAnalyticsResult::class)('2026-11-01');
        $bucketsAfter = $after->sections['qualifications']['primary_qualification']['buckets'];
        $afterHasB = collect($bucketsAfter)->contains(fn ($b) => ($b['academic_degree']['id'] ?? null) === $degreeB->id);
        $afterHasA = collect($bucketsAfter)->contains(fn ($b) => ($b['academic_degree']['id'] ?? null) === $degreeA->id);

        $this->assertTrue($afterHasB, 'the correction moves the Person into degree B\'s bucket');
        $this->assertFalse($afterHasA, 'and out of degree A\'s bucket entirely');
        $this->assertSame($before->overallHeadcount, $after->overallHeadcount, 'overall_headcount never changes because of a correction (D29)');
        $this->assertTrue($after->sections['qualifications']['primary_qualification']['reconciles_to_overall_headcount']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Unknown date
    // ------------------------------------------------------------------------------------------------------------

    public function test_an_unknown_obtained_on_is_never_the_wire_string_unknown(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $qualification = $this->record($person); // no obtained_on given

        $raw = $this->getJson($this->versionsUrl($person, $qualification))->assertOk()->getContent();
        $this->assertStringNotContainsString('"unknown"', $raw, 'the wire value is never the string "unknown"');

        $body = json_decode($raw, true);
        $this->assertNull($body['data'][0]['obtained_on']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Evidence completeness
    // ------------------------------------------------------------------------------------------------------------

    public function test_evidence_completeness_flags_the_current_holders_own_unevidenced_designation(): void
    {
        $person = $this->createPersonRecord();
        $this->insertRawQualification($person, $this->createSyntheticAcademicDegree()->id, null, true); // Primary with no audit trail at all

        $history = app(BuildPersonPrimaryQualificationHistory::class)($person);

        $this->assertSame([], $history->events);
        $this->assertCount(1, $history->gaps);
        $this->assertSame(BuildPersonPrimaryQualificationHistory::GAP_NO_DESIGNATION_EVIDENCE, $history->gaps[0]['code']);
    }

    public function test_evidence_completeness_flags_an_earlier_no_longer_current_gap_distinctly(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $x = $this->insertRawQualification($person, $this->createSyntheticAcademicDegree()->id, null, true); // unevidenced Primary
        $a = $this->recordViaHttp($person);

        $this->designateViaHttp($person, $a); // evidenced: X -> A

        $history = app(BuildPersonPrimaryQualificationHistory::class)($person);

        $this->assertCount(1, $history->gaps);
        $this->assertSame(BuildPersonPrimaryQualificationHistory::GAP_CHAIN_BROKEN, $history->gaps[0]['code'], 'A\'s own designation IS evidenced; the gap is further back, at X — distinct from GAP_NO_DESIGNATION_EVIDENCE');
        $this->assertSame($x->id, $history->gaps[0]['qualification_id']);
    }

    public function test_evidence_completeness_a_b_a_keeps_every_transition_with_no_gap(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $a = $this->recordViaHttp($person); // AUTO_FIRST: A
        $b = $this->recordViaHttp($person);
        $this->designateViaHttp($person, $b); // A -> B
        $this->designateViaHttp($person, $a); // B -> A

        $history = app(BuildPersonPrimaryQualificationHistory::class)($person);

        $this->assertCount(3, $history->events, 'every transition is kept, including the repeated qualification id A');
        $this->assertSame([], $history->gaps, 'the walk passes through the repeated id A without stopping there and finds the fully evidenced AUTO_FIRST start');
    }

    public function test_evidence_completeness_finds_a_gap_behind_a_repeated_qualification_id(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $c = $this->insertRawQualification($person, $this->createSyntheticAcademicDegree()->id, null, true); // unevidenced Primary
        $a = $this->recordViaHttp($person);
        $b = $this->recordViaHttp($person);

        $this->designateViaHttp($person, $a); // C -> A (evidenced)
        $this->designateViaHttp($person, $b); // A -> B (evidenced)
        $this->designateViaHttp($person, $a); // B -> A (evidenced)

        $history = app(BuildPersonPrimaryQualificationHistory::class)($person);

        $this->assertCount(3, $history->events);
        $this->assertCount(1, $history->gaps, 'an id-tracking walk that stopped at the second A would miss this gap entirely');
        $this->assertSame(BuildPersonPrimaryQualificationHistory::GAP_CHAIN_BROKEN, $history->gaps[0]['code']);
        $this->assertSame($c->id, $history->gaps[0]['qualification_id']);
    }

    public function test_evidence_completeness_is_identical_across_pages_of_events(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $this->insertRawQualification($person, $this->createSyntheticAcademicDegree()->id, null, true);
        $a = $this->recordViaHttp($person);
        $this->designateViaHttp($person, $a);

        $page1 = $this->getJson($this->primaryHistoryUrl($person, ['page' => 1, 'per_page' => 1]))->assertOk()->json();
        $page2 = $this->getJson($this->primaryHistoryUrl($person, ['page' => 2, 'per_page' => 1]))->assertOk()->json();

        $this->assertSame($page1['evidence_completeness'], $page2['evidence_completeness']);
        $this->assertNotSame($page1['events'], $page2['events'], 'sanity: the two pages are not literally the same slice');
    }

    public function test_auto_first_requires_field_level_evidence_on_the_record_event_itself(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $first = $this->recordViaHttp($person); // AUTO_FIRST: changes.is_primary = true
        $this->recordViaHttp($person); // a second one: changes.is_primary = false, never AUTO_FIRST

        $secondEntry = AuditEntry::query()->where('action', 'hr.person_qualification.record')->orderByDesc('id')->first();
        $this->assertFalse((bool) ($secondEntry->changes['is_primary'] ?? true));

        $history = app(BuildPersonPrimaryQualificationHistory::class)($person);
        $this->assertCount(1, $history->events, 'the second recording never appears as an event of any kind');
        $this->assertSame('AUTO_FIRST', $history->events[0]->type);
        $this->assertSame($first->id, $history->events[0]->qualificationId);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Provenance
    // ------------------------------------------------------------------------------------------------------------

    public function test_a_backfilled_version_shows_unknown_actor_provenance_never_a_primary_history_gap_code(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $qualification = $this->insertRawQualification($person, $this->createSyntheticAcademicDegree()->id, null, true);

        $raw = $this->getJson($this->versionsUrl($person, $qualification))->assertOk()->getContent();
        $body = json_decode($raw, true);

        $this->assertSame('BACKFILLED_UNKNOWN_ACTOR', $body['data'][0]['provenance']);
        $this->assertNull($body['data'][0]['created_by_principal_id']);
        $this->assertStringNotContainsString('GAP_NO_DESIGNATION_EVIDENCE', $raw, 'that code belongs only to the separate primary-history evidence_completeness object');
    }

    public function test_provenance_alone_never_distinguishes_a_backfilled_write_from_a_live_one(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $principal = $this->createPrincipal();

        // A backfilled row whose original record audit entry WAS found: created_by_principal_id is resolved.
        $resolvedBackfill = $this->insertRawQualification($person, $this->createSyntheticAcademicDegree()->id, null, false, $principal->id);
        // A genuinely new, live write — routed through the real HTTP endpoint so created_by_principal_id
        // is actually populated (record() alone never supplies an actor principal id).
        $live = $this->recordViaHttp($person);

        $this->assertSame('RECORDED', $this->currentVersion($resolvedBackfill)->provenance());
        $this->assertSame('RECORDED', $this->currentVersion($live)->provenance());
        $this->assertSame(
            $this->currentVersion($resolvedBackfill)->provenance(),
            $this->currentVersion($live)->provenance(),
            'identical provenance value for a resolved backfill and a live write — provenance cannot and does not distinguish them (D43)'
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // Pagination validation (D37)
    // ------------------------------------------------------------------------------------------------------------

    public function test_pagination_is_validated_on_both_the_versions_and_primary_history_endpoints(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $qualification = $this->record($person);

        foreach ([['page' => 0], ['page' => -1], ['page' => 'x'], ['per_page' => 0], ['per_page' => 101]] as $query) {
            $this->getJson($this->versionsUrl($person, $qualification, $query))->assertStatus(422);
            $this->getJson($this->primaryHistoryUrl($person, $query))->assertStatus(422);
        }

        $versionsBody = $this->getJson($this->versionsUrl($person, $qualification))->assertOk()->json();
        $this->assertSame(1, $versionsBody['meta']['current_page']);
        $this->assertSame(25, $versionsBody['meta']['per_page']);

        $historyBody = $this->getJson($this->primaryHistoryUrl($person))->assertOk()->json();
        $this->assertSame(1, $historyBody['meta']['current_page']);
        $this->assertSame(25, $historyBody['meta']['per_page']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Ownership binding
    // ------------------------------------------------------------------------------------------------------------

    public function test_versions_of_another_persons_qualification_is_404(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $other = $this->createPersonRecord();
        $theirs = $this->record($other);

        $this->getJson($this->versionsUrl($person, $theirs))->assertStatus(404);
        $this->postJson($this->correctionsUrl($person, $theirs), [
            'expected_version' => 1, 'academic_degree_id' => $this->createSyntheticAcademicDegree()->id, 'qualification_type_id' => null, 'obtained_on' => null, 'reason' => 'سبب',
        ])->assertStatus(404);
    }

    public function test_primary_history_never_leaks_another_persons_events(): void
    {
        $this->actingAsHrAdministrator();
        $a = $this->createPersonRecord();
        $b = $this->createPersonRecord();
        $qa = $this->recordViaHttp($a);
        $qb = $this->recordViaHttp($b);
        $this->designateViaHttp($a, $qa);
        $this->designateViaHttp($b, $qb);

        $historyForA = $this->getJson($this->primaryHistoryUrl($a))->assertOk()->json();
        $ids = collect($historyForA['events'])->pluck('qualification_id')->merge(collect($historyForA['events'])->pluck('previous_primary_qualification_id'))->filter()->all();

        $this->assertNotContains($qb->id, $ids, "Person A's primary-history never surfaces Person B's qualification ids");
    }

    // ------------------------------------------------------------------------------------------------------------
    // Audit-query scoping, literals verified (D38)
    // ------------------------------------------------------------------------------------------------------------

    public function test_primary_history_is_scoped_by_the_exact_literals_and_ignores_a_coerced_entry(): void
    {
        $person = $this->createPersonRecord();
        $qualification = $this->record($person);

        // A rogue entry using the guessed-wrong literal target_type RC3 used.
        $this->rawAuditEntry(['target_type' => 'hr.person_qualification', 'target_id' => $qualification->id, 'changes' => ['new_primary_qualification_id' => $qualification->id], 'metadata' => ['state_changed' => true]]);
        // A rogue entry under an unrelated action name.
        $this->rawAuditEntry(['target_type' => 'hr_person_qualification', 'action' => 'hr.person_qualification.some_other_action', 'target_id' => $qualification->id, 'changes' => ['new_primary_qualification_id' => $qualification->id], 'metadata' => ['state_changed' => true]]);
        // A rogue FAILED attempt — MUTATION entries are always SUCCEEDED in practice, but the query must still defend against one.
        $this->rawAuditEntry(['target_type' => 'hr_person_qualification', 'target_id' => $qualification->id, 'outcome' => Outcome::Rejected, 'changes' => ['new_primary_qualification_id' => $qualification->id], 'metadata' => ['state_changed' => true]]);

        $history = app(BuildPersonPrimaryQualificationHistory::class)($person);

        $this->assertSame([], $history->events, 'none of the three miscoded entries is ever picked up');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------------------------------------------------

    public function test_each_qualification_permission_authorizes_only_its_own_action(): void
    {
        $person = $this->createPersonRecord();

        $this->actingAsHrAdministrator();
        $qualification = $this->record($person);
        $degree = $this->createSyntheticAcademicDegree();

        $actions = [
            Perm::PERSON_QUALIFICATIONS_VIEW => fn () => $this->getJson($this->versionsUrl($person, $qualification)),
            Perm::PERSON_QUALIFICATIONS_RECORD => fn () => $this->postJson("/api/v1/hr/persons/{$person->id}/qualifications", ['academic_degree_id' => $degree->id]),
            Perm::PERSON_QUALIFICATIONS_DESIGNATE_PRIMARY => fn () => $this->postJson($this->designateUrl($person, $qualification)),
            Perm::PERSON_QUALIFICATIONS_CORRECT => fn () => $this->postJson($this->correctionsUrl($person, $qualification), [
                'expected_version' => (int) $this->currentVersion($qualification->refresh())->version_number,
                'academic_degree_id' => $this->createSyntheticAcademicDegree()->id, 'qualification_type_id' => null, 'obtained_on' => null, 'reason' => 'سبب',
            ]),
        ];

        foreach ($actions as $heldPermission => $ignored) {
            $this->viewerWith([$heldPermission]);

            foreach ($actions as $permission => $call) {
                $status = $call()->getStatusCode();
                if ($permission === $heldPermission) {
                    $this->assertNotSame(403, $status, "holding only {$heldPermission} must authorize its own action");
                } else {
                    $this->assertSame(403, $status, "holding only {$heldPermission} must never authorize {$permission}");
                }
            }
        }
    }
}
