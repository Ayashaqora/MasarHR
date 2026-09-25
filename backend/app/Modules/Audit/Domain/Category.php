<?php

namespace App\Modules\Audit\Domain;

/** S04 §10/§13/§14. */
enum Category: string
{
    case Mutation = 'MUTATION';
    case SecurityEvent = 'SECURITY_EVENT';
}
