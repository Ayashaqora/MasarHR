<?php

namespace App\Modules\Platform\Domain;

/**
 * S04 audit actor kind (docs/audit-command-infrastructure-specification.md §7). HUMAN always
 * carries a real security.principals id; SYSTEM never does (AUD-05/AUD-06).
 */
enum ActorType: string
{
    case Human = 'HUMAN';
    case System = 'SYSTEM';
}
