# Monthly Not-On-Duty Report Foundation Specification

Stage: **S39** — REPORT-3, Monthly Not-On-Duty.
Architecture status: **FROZEN** (specification only). Implementation is not part of this document and is authorized separately.
Baseline: `origin/develop` = `61a7d4a72ccf8186c5e165af8d41232a94c88d47` (S38; tree `89de27208a5f11ef42a0834128a306e4e4b23259`;
tag `s38-employment-status-expiry-followup` peels to that commit). `origin/main` = `c2fd87e8fe8658edeb5d27ba5fa71c2de8177085`.
Correction 01 (Person identity projection) applied by the Architecture Authority.
Dependency: **S37** (`docs/monthly-workforce-reporting-semantics-foundation-specification.md`). No earlier frozen document is modified.

## §S39.1 Purpose

The backend foundation for REPORT-3. It answers one question: *which unique Persons were NO_ON_DUTY for the entire active
portion of the requested month, according to the already-frozen S37 classification?* It is a **projection over the S37
canonical dataset**, not a new engine, and it introduces no business rule of its own beyond the decisions below.

## §S39.2 Canonical source (S37 reuse rule)

The ONLY workforce population source is `ListMonthlyReportingPopulation` and its S37 result model
(`MonthlyReportingPopulation` → `MonthlyReportingPersonRow` → `MonthlyRelationshipSegment`):

```
month → ListMonthlyReportingPopulation → MonthlyReportingPopulation
      → filter Person rows: dutyClassification == NO_ON_DUTY
      → REPORT-3 projection { summary, rows }
```

Forbidden: a second population query; independent relationship-population logic; independent monthly status calculation;
duplicate duty-classification logic; recalculating S37 semantics inside S39. S39 makes **one** S37 call per request and derives
summary, metadata and rows from that one result.

## §S39.3 REPORT-3 population

Exactly the Persons whose S37 person-level `dutyClassification == NO_ON_DUTY`. A Person appears at most once; several
relationships in the month never duplicate the Person. `HAS_ON_DUTY` is excluded; `INDETERMINATE` is excluded from the rows.
A Person with any on_duty interval (explicit or S32-derived) during the active part of the month is `HAS_ON_DUTY` and is not in
REPORT-3. There is no "any absence during the month" semantics.

## §S39.4 INDETERMINATE

`INDETERMINATE` is never treated as `NO_ON_DUTY`. S39 exposes `indeterminate_count` as report-level **data-quality metadata**: it is
not part of `total_not_on_duty_persons`, not a row, not a reason and not an absence category. It is counted from the SAME S37 result
(no second query). It preserves cases such as unresolved status gaps, `UNKNOWN_LEGACY` relationships and new relationships with
incomplete status coverage (by the S10 rule a relationship that starts inside the month always has an unresolved first active day,
so it is `HAS_ON_DUTY` or `INDETERMINATE`, never `NO_ON_DUTY`).

## §S39.5 Summary contract

The only authoritative business total: **`total_not_on_duty_persons`** = the number of unique Person rows in REPORT-3.
Invariant: it equals the number of REPORT-3 Person rows **before any presentation pagination**. Metadata: `indeterminate_count`.
No authoritative totals grouped by reason, organizational unit, workplace, relationship end reason, status detail, gender, age,
experience or qualification (a Person can have several relationships, reasons and workplace segments, so such buckets would not
partition the Persons; they need a later stage and explicit aggregation semantics).

## §S39.6 Reason model

S37 reason semantics are preserved unchanged. `lastNonOnDutyReason` and `relationshipEndReason` are **per employment
relationship**, independent, and neither overwrites the other. A Person may have one or several relationships, several different
last non-on-duty reasons, and zero, one or several relationship end reasons. S39 creates **no** Person-level reason of any name
(`personReason`, `primaryReason`, `finalReason`, `monthlyReason` or equivalent); the row keeps the relationship-level reasons.
Status labels may be resolved centrally from `ref.employment_status_details` (`name_ar`, `name_en`) by the existing `status_detail_id`
or code — once, in the read model, with one batched reference lookup (never per row, never per output format).

## §S39.7 Legacy status rule

S39 does not alter S37 classification. A legacy explicit status that S37 classifies as known non-on-duty (for example the retired
`wants_to_return`) yields a `NO_ON_DUTY` Person with that reason; S39 creates no exception. Recorded as deferred data/domain debt only
(§S39.21); no S34 or S37 reopening.

## §S39.8 Relationship representation

A REPORT-3 Person row keeps every S37 relationship segment needed to explain the result, without collapsing them: relationship id,
original and clipped bounds, employment type facts already present, `endKnowledgeState`, `endIsUncertain`, `endedTerminally`,
`lastNonOnDutyReason`, `relationshipEndReason`. A same-month reappointment remains one Person with several relationship segments.

## §S39.9 Workplace model

S37 workplace interval segments are preserved as they are: states `RESOLVED`, `UNRESOLVED`, `AMBIGUOUS_MOVEMENT_STATE`,
`PARTIAL_ALLOCATION`; unit ids, source, interval bounds, weekday allocations and competing movements where present. No
`current_workplace`, `primary_workplace` or `reporting_workplace` exists. A Partial Secondment is never converted into percentages,
attendance days or time fractions, and several segments remain several. Unit names are not resolved by S39 (ids only).

## §S39.10 Permission and scope

New dedicated permission **`hr.monthly_not_on_duty.view`**, granted to no role by its seed. **Plain RBAC**: S39 introduces no
organizational-unit scope. Reason: the canonical Person may have several workplace segments, partial allocations, unresolved
workplace or an ambiguous movement state, and no frozen rule selects one unit for authorization. Any reporting scope requires a later
explicit architecture decision. S27's open scope question (§S27.12) is left as is. No existing permission is reused, and this one
grants nothing else.

## §S39.11 API shape

ONE read-only backend endpoint returning, from ONE report computation, the report metadata, the summary and the REPORT-3 Person rows.
No separate summary endpoint, no separate drilldown endpoint, no write method. Every `api/v1/hr` route must carry a `permission:`
middleware and none may expose DELETE (existing guard `HumanResources/ScopeBoundaryTest`).

## §S39.12 Route URI (verified against the current guards, from source)

Current predicates, read from the tests:

| Guard | Source | Predicate |
|---|---|---|
| S27 | `ReportingAsOfFoundationTest` (route URI loop) | `/report|export|dashboard|as-of|pdf|xlsx|csv/i` must not match the URI |
| S37 | `MonthlyWorkforceReportingFoundationTest` `test_as_…` | `/monthly(?!-cadre-categories\|CadreCategory)\|report\|export\|dashboard\|pdf\|xlsx\|csv\|print/i` must not match the URI, and `/ListMonthlyReportingPopulation\|MonthlyReporting/` must not match the action name |
| HR scope | `HumanResources/ScopeBoundaryTest` | URI must not contain `supervisory`, `leave`, `renewal`, `professional-history`, `job-history`, `export`, `report`; a permission middleware is required; no DELETE |

Candidate check:

| URI | S27 | S37 | HR scope |
|---|---|---|---|
| `GET /api/v1/hr/monthly-not-on-duty` (the preferred candidate) | pass | **FAIL** (contains `monthly`) | pass |
| `GET /api/v1/hr/not-on-duty` | pass | pass | pass |

The preferred URI does **not** pass every current guard, so it is not frozen, and no guard is modified or weakened. The smallest
neutral URI that passes all of them is frozen:

**`GET /api/v1/hr/not-on-duty`**, with the month given as the `month` query parameter. The endpoint remains conceptually the
Monthly Not-On-Duty read endpoint. Class names must also keep the existing file-name and action-name guards passing (no `Pdf`, `Xlsx`,
`Csv`, `Export`, `Dashboard`, `Chart`, `Print`, `MonthlyReporting`; no HR command named with `Leave` or `Supervisory`).

## §S39.13 Public input

One business input: **`month`**, the S37 convention: an explicit first day `YYYY-MM-01` (`date_format:Y-m-d`, day must be `01`).
A non-first-day or malformed value is rejected with a validation error (422, `errors.month`) before S37 is called (S37 itself throws
`InvalidArgumentException` for it). No alternate month formats. No public filters for reason, relationship end reason, organizational
unit, workplace, workplace state, gender, age, experience, qualification or person ids; the internal S37 `personIds` capability is not
exposed.

## §S39.14 Response contract (conceptual; exact names follow repository API conventions, the business meaning is frozen)

```
{
  month: "YYYY-MM-01", month_start: "YYYY-MM-01", next_month_start: "YYYY-MM-01",
  summary: { total_not_on_duty_persons: n, indeterminate_count: n },
  rows: [ {
      person: { person_id, national_id, full_name, gender_id, birth_date,
                qualifications: [...], qualification_semantics: "CURRENT_RECORDED_PERSON_FACTS" },
      duty_classification: "NO_ON_DUTY",
      relationships: [ {
          employment_relationship_id, employment_type…, effective_from, effective_to,
          clipped_from, clipped_to, end_knowledge_state, end_is_uncertain, ended_terminally,
          last_non_on_duty_reason: {…} | null, relationship_end_reason: {…} | null,
          status_segments: [...], workplace_segments: [...], work_schedule_segments: [...]
      } ]
  } ],
  semantics: { rows_are_unique_persons: true, reasons_scope: "PER_RELATIONSHIP",
               workplace: "INTERVAL_SEGMENTS", qualifications: "CURRENT_RECORDED_PERSON_FACTS",
               indeterminate: "EXCLUDED_DATA_QUALITY_METADATA", age: "NOT_EXPOSED",
               person_identity: "DISPLAY_FACTS_ONLY" }
}
```

Rows are unique Persons; reasons are relationship-level; workplaces are interval segments; qualifications are current-recorded
facts, not month-as-of facts; no age, age band or percentage is exposed. A Person is identified technically by `person_id` and
humanly by `national_id` and `full_name` (§S39.14A).

## §S39.14A Person identity projection (Correction 01)

The Person projection MUST carry: `person_id`, `national_id`, `full_name`, `gender_id`, `birth_date`, `qualifications`. The report is
not delivered with `person_id` as its only human identity fact.

**Existing Person convention (read from the repository, nothing invented):**
- `hr.persons.national_id` (`varchar(64)`, unique `persons_national_id_unique`) — the Person's permanent business reference, stored by the
  S09 Person aggregate (`Person` model; `PersonResource` already returns it as `national_id`). `id` (UUID) is the technical key.
- `hr.persons.full_name_ar` (`varchar(255)`, nullable, CHECK `persons_full_name_ar_not_blank_check`) — the **only** Person name field
  (S24, `docs/person-profile-foundation-specification.md` §S24.2/§S24.11): a single trimmed full name, **no name parts and no English
  name**, required for new Persons and `NULL` for legacy Persons. `PersonResource` exposes it as `full_name_ar`; Employee 360 displays it,
  or "غير مسجَّل / Not recorded" when `NULL`.
- Therefore the canonical **`full_name` = the stored `full_name_ar` value, exactly as stored**: no concatenation, no ordering rule, no
  splitting, no transliteration. A legacy `NULL` stays `null` — never a fabricated or placeholder string. Any "not recorded" wording is a
  presentation concern of a consumer, not a report fact.

**Rules**
1. `person_id` remains the technical stable identifier.
2. `national_id` and `full_name` are projection/display facts only. They MUST NOT affect population membership, `NO_ON_DUTY` or
   `INDETERMINATE` classification, reasons, totals or workplace semantics.
3. No new Person name model, identity table, duplicated identity persistence or report-specific identity persistence. Identity is read
   from the existing `hr.persons` row; nothing is stored.
4. S37 is NOT modified to expose these fields. S39 remains a projection over the canonical S37 population.
5. Enrichment is performed **in batch** for the Person ids of the REPORT-3 rows taken from the single S37 computation (one set-based
   lookup on `hr.persons`, keyed by id). No per-row or per-Person query (no N+1). The lookup only adds display fields to rows that already
   exist; it can neither add nor remove a row, and it is not a second population computation.
6. The decision authorizes national id and full name for this **internal authenticated HR report only** (behind the permission of
   §S39.10). It is NOT generalized to any other reporting, export or output surface; each such surface needs its own decision.

## §S39.15 Ordering

Deterministic, reusing S37: Persons by `person_id`; relationships by clipped start then id; segments chronological; qualifications by
`(created_at, id)`. No business ranking is invented.

## §S39.16 Pagination policy

S37 computes the complete month. S39 does **not** add a second database population query to paginate. The canonical computation comes
first, the REPORT-3 projection second, and any presentation pagination only afterwards. A mandatory pagination contract is **not**
frozen. If pagination is implemented it follows the existing list convention (`page`, `per_page`), is applied after the projection, and
`total_not_on_duty_persons` remains the complete population (never the page size). Query push-down is deferred until measured.

## §S39.17 Database policy

No report persistence, no materialized or report table, no duplicated reporting facts, no business-data migration. One migration is
expected only to register `hr.monthly_not_on_duty.view` in `security.permissions`, following the S31/S38 seed-migration precedent
(default deny; granted to no role; reversible). That seed is not report persistence. No performance index is authorized by this freeze.

## §S39.18 Performance policy

**MEASURE_BEFORE_OPTIMIZING.** Audit evidence: S37 uses a constant 8 statements, no N+1, no current blocker; large-scale behavior is
unmeasured. The implementation must include a realistic synthetic measurement (population size, statement count, time). If an index
appears necessary, the implementer stops and reports the measured evidence before adding it, unless an existing architecture
convention clearly permits it.

## §S39.19 Output-layer reuse

The read model must be reusable later by a web screen, drilldown, XLSX, PDF and print. S39 implements none of them. The business
semantics (population, classification, reasons, workplace interpretation, labels) live in the S37/S39 read model; future formatters
must not recalculate any of them.

## §S39.20 Test contract for the future implementation

Real PostgreSQL where repository conventions require it. The S39 tests prove at minimum:

1. S37 canonical dataset reuse. 2. No second monthly population engine (a code-level guard: the S39 classes run no population query
of their own and call `ListMonthlyReportingPopulation` once). 3. Only `NO_ON_DUTY` Persons enter the rows. 4. `HAS_ON_DUTY` excluded.
5. `INDETERMINATE` excluded from rows. 6. `INDETERMINATE` counted separately in metadata. 7. A new-hire / unresolved case is not falsely
reported as `NO_ON_DUTY`. 8. One unique Person row across several relationships. 9. A same-month reappointment keeps several
relationship segments. 10. `lastNonOnDutyReason` stays per relationship. 11. `relationshipEndReason` stays separate. 12. Bounded-status
expiry / derived on_duty behavior inherited from S37. 13. Explicit-successor behavior inherited from S37. 14. Workplace segments
preserved. 15. Partial allocation preserved. 16. Ambiguous and unresolved workplace preserved. 17. `total_not_on_duty_persons` equals
the complete REPORT-3 Person population. 18. The dedicated permission is enforced. 19. An unauthorized request is denied.
20. Month boundaries are deterministic (first-day validation, 422 on other days). 21. No HR business-data mutation. 22. No report
persistence. 23. The existing 67 S37 tests remain regression guards. 24. The existing S27 and S37 route guards remain unchanged and
pass. Identity projection (Correction 01), additionally proving: 25. exactly one canonical S37 population computation per request, with
identity enrichment not counted as a population computation. 26. Identity enrichment does not change row membership (the row set and
`total_not_on_duty_persons` are identical with and without it). 27. `national_id` maps to the correct Person. 28. `full_name` equals the
stored `hr.persons.full_name_ar` (the existing Person-domain convention), and a legacy `NULL` name is returned as `null`, never fabricated.
29. A same-month reappointment still yields one Person report row with its identity. 30. No N+1 Person identity lookup (the statement
count does not grow with the number of rows; identity is one batched lookup). 31. No S37 modification (the S37 classes and their 67 tests
are unchanged).

Mutation proofs for the frozen predicates and a realistic synthetic performance measurement are also required.

Known guard updates the implementation will need (the same pattern as S31/S38, not new rules): the human_resources permission
counts in `MigrationLifecycleTest`, the permission catalog list in `ApiEndpointsTest`, and the rollback ordering if a seed
migration is added.

## §S39.21 Explicit exclusions and deferred debt

Out of S39: REPORT-1, REPORT-2, REPORT-4, REPORT-5; a frontend report screen and any analytics dashboard; XLSX, PDF, print, CSV;
age calculations and bands; experience and years of service; Position/Post, authorized establishment and vacancies; leave balances,
attendance and absence-day totals; supervisory assignment and promotion; Excel/CSV import; notification delivery; organizational
reporting scope; reason-, unit- and workplace-based official aggregation; changes to S31, S32, S34, S37 semantics or S38.

Deferred debt, recorded and not resolved here:
- a retired legacy code such as `wants_to_return` classified as known non-on-duty (S34/S37 data/domain debt, §S39.7);
- (Resolved by Correction 01, no longer debt) Person identity display fields: `national_id` and `full_name` are decided and frozen in
  §S39.14A for this internal authenticated HR report only; they are not generalized to other reporting or export surfaces;
- unit name resolution and any per-unit or per-reason official totals;
- organizational reporting scope for monthly data (S27 §S27.12 remains open);
- query push-down, pagination and any index, pending measurement.
