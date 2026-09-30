<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// S31 (docs/movement-expiry-followup-foundation-specification.md §S31.11): the 7-day movement expiry
// follow-up scan. The definition holds no business rule — the command calls the idempotent
// ScanMovementExpiryFollowUps service — so an overlapping or repeated run is harmless;
// withoutOverlapping() only avoids redundant work.
Schedule::command('masar:hr:scan-movement-expiry-followups')
    ->dailyAt('01:00')
    ->withoutOverlapping(60)
    ->name('hr-movement-expiry-followups');

// S38 (docs/employment-status-expiry-followup-specification.md §S38.12): the 7-day employment status expiry
// follow-up scan, independent of the S31 movement scan above (separate command, separate overlap lock, its own
// time). The definition holds no business rule — the command calls the idempotent
// ScanEmploymentStatusExpiryFollowUps service — so an overlapping or repeated run is harmless.
Schedule::command('masar:hr:scan-employment-status-expiry-followups')
    ->dailyAt('01:15')
    ->withoutOverlapping(60)
    ->name('hr-employment-status-expiry-followups');
