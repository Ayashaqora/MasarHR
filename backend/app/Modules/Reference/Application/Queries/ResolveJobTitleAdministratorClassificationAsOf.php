<?php

namespace App\Modules\Reference\Application\Queries;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitleAdministratorClassification;
use Illuminate\Support\Carbon;

/**
 * As-of reader for ref.job_title_administrator_classifications (S06 spec §12.3/§14). Pure read,
 * no mutation. Returns null when no classification period covers the given date — UNRESOLVED,
 * never a guessed boolean.
 */
final class ResolveJobTitleAdministratorClassificationAsOf
{
    public function __invoke(JobTitle $jobTitle, string|Carbon $date): ?bool
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        $classification = JobTitleAdministratorClassification::query()
            ->where('job_title_id', $jobTitle->getKey())
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();

        return $classification === null ? null : (bool) $classification->is_administrator;
    }
}
