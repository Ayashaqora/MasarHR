<?php

namespace App\Modules\Reference\Application\Queries;

use App\Modules\Reference\Domain\Support\ArabicLookupNormalizer;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a raw Arabic marital-status source value (as it appears in HR source data, gendered
 * spellings included) to its canonical ref.marital_statuses record, via
 * ref.marital_status_aliases (S05 CORRECTIVE-01 §7). Read-only reference-resolution
 * infrastructure only — S05 does not implement Excel import; this is the deterministic lookup
 * contract a future Import/Data Quality subsystem will call.
 *
 * Contract:
 *  - input:  raw Arabic source value
 *  - output: the canonical MaritalStatus, or null ("UNRESOLVED")
 *
 * It MUST NOT, and does not:
 *  - create reference values automatically
 *  - return a placeholder/OTHER value for anything unmatched
 *  - guess or fuzzy-match
 *  - use employee gender to infer a status
 *  - mutate database state (this is a pure read: one SELECT against the alias table, then one
 *    lookup of the matched MaritalStatus)
 *
 * Resolution is independent of ref.marital_statuses.is_active: a deactivated canonical status
 * must still be identifiable for historical data (S05 CORRECTIVE-01 §8). Deactivation only
 * affects whether a status is offered for new selection elsewhere — never whether it can still be
 * resolved here.
 */
final class ResolveMaritalStatusByArabicSourceValue
{
    public function __invoke(string $rawSourceValue): ?MaritalStatus
    {
        $normalized = ArabicLookupNormalizer::normalize($rawSourceValue);

        if ($normalized === '') {
            return null;
        }

        $maritalStatusId = DB::table('ref.marital_status_aliases')
            ->where('normalized_alias', $normalized)
            ->value('marital_status_id');

        if ($maritalStatusId === null) {
            return null;
        }

        return MaritalStatus::query()->find($maritalStatusId);
    }
}
