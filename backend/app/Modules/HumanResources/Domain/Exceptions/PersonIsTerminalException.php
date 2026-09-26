<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * This Person's employment eligibility is terminal (شهيد/وفاة — spec §10); no new Employment
 * Relationship may ever be created for them. This is the entire enforcement S09 owns for the
 * terminal invariant — it does not model which detailed status caused it. Maps to 409.
 */
final class PersonIsTerminalException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This person is marked terminal and is no longer eligible for employment.');
    }
}
