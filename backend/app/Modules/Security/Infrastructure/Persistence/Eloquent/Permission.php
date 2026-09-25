<?php

namespace App\Modules\Security\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** Read-only from the application's perspective in S03: rows come only from migrations. */
class Permission extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'security.permissions';

    protected $keyType = 'string';

    public $incrementing = false;
}
