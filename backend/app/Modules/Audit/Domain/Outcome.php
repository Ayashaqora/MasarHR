<?php

namespace App\Modules\Audit\Domain;

/** S04 §10. MUTATION entries are always SUCCEEDED (§13) — REJECTED only occurs on SECURITY_EVENT. */
enum Outcome: string
{
    case Succeeded = 'SUCCEEDED';
    case Rejected = 'REJECTED';
}
