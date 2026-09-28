<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * UpdatePersonProfile's scoped UPDATE matched no row for the supplied expected_version — the Person
 * was modified concurrently (docs/person-profile-foundation-specification.md §S24.8). Maps to 409,
 * the same optimistic-concurrency outcome as S07's OrganizationStaleVersionException.
 */
final class PersonStaleVersionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The person was modified by another request. Reload and try again.');
    }
}
