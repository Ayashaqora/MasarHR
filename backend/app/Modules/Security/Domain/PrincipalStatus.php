<?php

namespace App\Modules\Security\Domain;

/**
 * §14 of the S03 authorization: only ACTIVE and DISABLED are supported. LOCKED/PENDING/EXPIRED are
 * explicitly out of scope unless the Architecture Authority authorizes them later.
 */
enum PrincipalStatus: string
{
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';
}
