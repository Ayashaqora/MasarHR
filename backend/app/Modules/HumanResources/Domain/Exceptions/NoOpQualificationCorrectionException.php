<?php

namespace App\Modules\HumanResources\Domain\Exceptions;

use RuntimeException;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.5 step 4, D11/D16): the
 * proposed `(academic_degree_id, qualification_type_id, obtained_on)`, compared NULL-safely,
 * exactly matches the current version's own values. Nothing is written. Maps to 422 with
 * `{"message": "..."}` and no `errors` key — no single field is individually invalid.
 */
final class NoOpQualificationCorrectionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This correction would not change anything about the qualification.');
    }
}
