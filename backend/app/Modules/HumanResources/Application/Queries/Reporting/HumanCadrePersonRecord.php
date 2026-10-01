<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * The ONE canonical REPORT-1 record of a Person for a month (docs/human-cadre-report-foundation-specification.md §S41.8). Every
 * summary and drilldown is derived from the collection of these records — none reconstructs the population. Immutable; never
 * persisted. Each nested value is single-valued, so no dimension can multiply the Person.
 */
final class HumanCadrePersonRecord
{
    /**
     * @param  array{state: string, id: string|null, code: string|null, name_ar: string|null, name_en: string|null}  $gender  RECORDED | NOT_RECORDED (CURRENT_RECORDED)
     * @param  array<string, mixed>  $selectedRelationship  the latest qualifying relationship: id, effective_from, effective_to, end_knowledge_state, employment_type{id, code, name_ar, name_en}
     * @param  array<string, mixed>  $specialty  state RESOLVED | NOT_RECORDED, with the specialty value when resolved
     * @param  array<string, mixed>  $cadre  state RESOLVED (code + labels) | UNCLASSIFIED (unclassified_reason NOT_RECORDED | UNMAPPED)
     * @param  array<string, mixed>  $qualification  state PRIMARY (the Primary Qualification, CURRENT_RECORDED) | NOT_RECORDED
     * @param  array{state: string, years: int|null, band: string}  $age
     * @param  array<string, mixed>  $service  state CALCULABLE (service_days, completed_service_years, remaining_service_days, display, band) | INCOMPLETE (reason)
     * @param  list<string>  $dataQuality  report-derived indicators, never persisted
     */
    public function __construct(
        public readonly string $personId,
        public readonly ?string $nationalId,
        public readonly ?string $fullNameAr,
        public readonly array $gender,
        public readonly array $selectedRelationship,
        public readonly string $classificationDate,
        public readonly array $specialty,
        public readonly array $cadre,
        public readonly array $qualification,
        public readonly array $age,
        public readonly array $service,
        public readonly array $dataQuality,
    ) {}
}
