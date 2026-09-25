<?php

namespace App\Modules\Platform\Domain;

/**
 * S04 audit entry source (§10). SYSTEM is reserved/unused in v1.0 — no queue/job entry point
 * exists yet — kept in the enum for forward extensibility rather than added later as a breaking
 * change.
 */
enum Source: string
{
    case Http = 'HTTP';
    case Cli = 'CLI';
    case System = 'SYSTEM';
}
