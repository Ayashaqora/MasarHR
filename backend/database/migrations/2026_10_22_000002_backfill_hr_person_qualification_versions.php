<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S48 step 3/4 of §S48.18's cutover plan (docs/person-qualification-history-foundation-specification.md
 * §S48.12/§S48.18, D19/D30/D36/D43): the migration-time version-1 backfill, followed by the
 * mandatory transactional verification before the old columns are ever dropped (that is a separate,
 * later migration — step 5).
 *
 * No fabrication in either direction (§S48.12): `obtained_on` is always NULL for a backfilled
 * row (the pre-S48 schema never recorded it); `created_by_principal_id` is resolved ONLY by
 * matching the parent qualification's own `id` against a pre-existing
 * `audit.audit_entries` row (`action = 'hr.person_qualification.record'`,
 * `target_type = 'hr_person_qualification'`, `outcome = 'SUCCEEDED'`) and taking that entry's own
 * `actor_principal_id` — never the identity or time of whatever runs this migration. A row with no
 * matching audit entry (or whose matching entry has no actor_principal_id, e.g. a SYSTEM actor)
 * gets `created_by_principal_id = NULL`, i.e. `provenance = BACKFILLED_UNKNOWN_ACTOR` (D36/D43). A
 * row WITH a resolved actor is `provenance = RECORDED` even though it is a backfilled row — D43 is
 * explicit that `provenance` never implies write timing.
 *
 * Verification is a VALUE comparison, not a row-count comparison alone (§S48.18 step 4): for every
 * existing `hr.person_qualifications` row, exactly one `version_number = 1` row must exist whose
 * `academic_degree_id`, `qualification_type_id`, and `created_at` exactly match the parent row's
 * own values. This migration runs inside Laravel's own per-migration transaction (the default for
 * the `pgsql` driver); any verification failure throws, so the backfill is rolled back entirely
 * rather than left partially applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::insert(<<<'SQL'
            INSERT INTO hr.person_qualification_versions
                (id, person_qualification_id, person_id, version_number, academic_degree_id,
                 qualification_type_id, obtained_on, is_current, reason, created_by_principal_id, created_at)
            SELECT
                gen_random_uuid(),
                pq.id,
                pq.person_id,
                1,
                pq.academic_degree_id,
                pq.qualification_type_id,
                NULL,
                true,
                NULL,
                resolved.actor_principal_id,
                pq.created_at
            FROM hr.person_qualifications pq
            LEFT JOIN LATERAL (
                SELECT ae.actor_principal_id
                FROM audit.audit_entries ae
                WHERE ae.target_type = 'hr_person_qualification'
                  AND ae.action = 'hr.person_qualification.record'
                  AND ae.outcome = 'SUCCEEDED'
                  AND ae.target_id = pq.id::text
                ORDER BY ae.occurred_at ASC, ae.id ASC
                LIMIT 1
            ) resolved ON true
            WHERE NOT EXISTS (
                SELECT 1 FROM hr.person_qualification_versions v WHERE v.person_qualification_id = pq.id
            )
            SQL);

        $mismatchCount = DB::selectOne(<<<'SQL'
            SELECT count(*) AS mismatches
            FROM hr.person_qualifications pq
            LEFT JOIN hr.person_qualification_versions v
                ON v.person_qualification_id = pq.id AND v.version_number = 1
            WHERE v.id IS NULL
               OR v.academic_degree_id IS DISTINCT FROM pq.academic_degree_id
               OR v.qualification_type_id IS DISTINCT FROM pq.qualification_type_id
               OR v.created_at IS DISTINCT FROM pq.created_at
            SQL)->mismatches;

        if ((int) $mismatchCount !== 0) {
            throw new RuntimeException(
                "S48 backfill verification failed: {$mismatchCount} hr.person_qualifications row(s) ".
                'have no matching version_number = 1 row, or its academic_degree_id/qualification_type_id/'.
                'created_at does not exactly match the parent row. Aborting before any old column is dropped.'
            );
        }

        $rowCount = DB::selectOne('SELECT count(*) AS n FROM hr.person_qualifications')->n;
        $versionCount = DB::selectOne('SELECT count(*) AS n FROM hr.person_qualification_versions WHERE version_number = 1')->n;
        if ((int) $rowCount !== (int) $versionCount) {
            throw new RuntimeException(
                "S48 backfill verification failed: hr.person_qualifications has {$rowCount} row(s) but ".
                "hr.person_qualification_versions has {$versionCount} version_number = 1 row(s)."
            );
        }
    }

    public function down(): void
    {
        // Rollback / recovery policy (§S48.18, corrected gate D42, precision corrected D44): the
        // gate is deliberately conservative, not a precise "no real S48 write yet" detector — it
        // can trip immediately after THIS backfill if it resolved even one real actor from
        // history. That is accepted explicitly as the correct side to err on.
        //
        // The SAME check (byte-for-byte) is repeated as the very first statement of 000003's and
        // 000004's own down() too — not shared via a common method, because each migration file
        // returns its own anonymous class with no shared base, database/migrations/ is outside
        // Composer's autoload map, and composer.json itself is out of scope for this change.
        // Rollback runs most-recent-migration-first (000004, then 000003, then this file last), so
        // by the time THIS down() would run, 000004's and 000003's own changes would already have
        // happened if the gate lived only here. Each down() in the chain refuses before its OWN
        // first change, so a gate failure anywhere leaves the entire chain untouched — never a
        // partial reversal.
        $liveWriteExists = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM hr.person_qualification_versions WHERE created_by_principal_id IS NOT NULL
            ) AS exists_flag
            SQL)->exists_flag;

        if ($liveWriteExists) {
            throw new RuntimeException(
                'S48 rollback refused: at least one hr.person_qualification_versions row has a '.
                'known created_by_principal_id (from the backfill resolving a real actor, or from '.
                'a genuine post-migration write). Reverting would silently destroy real '.
                'obtained_on/actor data. Refused before any schema, permission, version-data, or '.
                'migrations-record change — no partial reversal is performed.'
            );
        }

        // Coordinated, maintenance-window-only rollback (§S48.18): a DELETE against
        // hr.person_qualification_versions runs into two protections that are correct for every
        // normal application write and must never be weakened there — the unconditional
        // immutability trigger (MA004, person_qualification_versions_immutable: rejects every
        // DELETE, no exception) and the deferred "at least one current version" constraint
        // trigger (person_qualification_versions_at_least_one_current). Both are disabled for the
        // duration of this one statement, inside this migration's own transaction, and
        // unconditionally re-enabled immediately after (even if the DELETE itself fails) — the
        // same technique ConcurrencyTest::forceDeletePersonQualificationFixtures() and
        // MigrationLifecycleTest's own test-only cleanup already use for the identical trigger
        // pair. Nothing about the normal request-handling code path is touched: these two
        // statements exist only here, bracketing this one migration-time DELETE.
        DB::statement('ALTER TABLE hr.person_qualification_versions DISABLE TRIGGER person_qualification_versions_immutable');
        DB::statement('ALTER TABLE hr.person_qualification_versions DISABLE TRIGGER person_qualification_versions_at_least_one_current');
        try {
            DB::delete('DELETE FROM hr.person_qualification_versions WHERE version_number = 1');
        } finally {
            DB::statement('ALTER TABLE hr.person_qualification_versions ENABLE TRIGGER person_qualification_versions_immutable');
            DB::statement('ALTER TABLE hr.person_qualification_versions ENABLE TRIGGER person_qualification_versions_at_least_one_current');
        }
    }
};
