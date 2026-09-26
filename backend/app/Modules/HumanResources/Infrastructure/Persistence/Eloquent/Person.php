<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * S09's Person aggregate (spec §4): identity only — national_id is the permanent business
 * reference, id (UUID) is the technical PK. is_terminal is set exactly once, by
 * EndEmploymentRelationship, and never unset by any command in this module.
 */
#[Fillable(['national_id'])]
class Person extends Model
{
    protected $table = 'hr.persons';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $person): void {
            if (! $person->getKey()) {
                $person->{$person->getKeyName()} = (string) Str::uuid7();
            }

            if ($person->version === null) {
                $person->version = 1;
            }

            if ($person->is_terminal === null) {
                $person->is_terminal = false;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function employmentRelationships(): HasMany
    {
        return $this->hasMany(EmploymentRelationship::class, 'person_id');
    }
}
