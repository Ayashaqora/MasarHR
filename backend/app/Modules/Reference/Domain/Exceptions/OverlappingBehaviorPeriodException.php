<?php

namespace App\Modules\Reference\Domain\Exceptions;

use RuntimeException;

/**
 * The proposed [effective_from, effective_to) period overlaps an existing period for the same
 * source dimension row — surfaced by a PostgreSQL EXCLUDE constraint (SQLSTATE 23P01), translated
 * here into a 409 domain rejection. Originally introduced for
 * ref.employment_status_detail_behaviors (S05 §12); reused as-is by every S06 temporal mapping
 * table (ref.specialty_cadre_category_mappings, ref.job_title_administrator_classifications,
 * ref.contract_type_population_mappings) since the failure mode is identical regardless of which
 * table triggered it (S06 spec §15) — not duplicated per table.
 */
final class OverlappingBehaviorPeriodException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This period overlaps an existing behavior period for this employment status detail.');
    }
}
