<?php

namespace App\Modules\HumanResources\Domain;

/**
 * S34: the only two Return Intention values. Absence of a period means NOT RECORDED — there is
 * deliberately no third value. Not a reference catalog: a constrained domain value.
 */
enum ReturnIntention: string
{
    case WantsToReturn = 'WANTS_TO_RETURN';
    case DoesNotWantToReturn = 'DOES_NOT_WANT_TO_RETURN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
