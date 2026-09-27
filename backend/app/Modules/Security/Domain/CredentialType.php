<?php

namespace App\Modules\Security\Domain;

/** S03 defines only PASSWORD. */
enum CredentialType: string
{
    case Password = 'PASSWORD';
}
