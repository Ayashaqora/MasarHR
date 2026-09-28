<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * S09's Person aggregate (spec §4): identity only — national_id is the permanent business
 * reference, id (UUID) is the technical PK. is_terminal is set exactly once, by
 * EndEmploymentRelationship, and never unset by any command in this module.
 *
 * S24 (docs/person-profile-foundation-specification.md, ADR-S24-001) adds the Person's CURRENT
 * profile attributes: full_name_ar, gender_id, marital_status_id, birth_date, birth_place. All are
 * nullable at the database level so pre-S24 Persons stay valid with honestly-unknown (NULL) values;
 * CreatePerson requires the first four for every NEW Person. They are changed only through the
 * explicit UpdatePersonProfile command — never through national_id or any employment command.
 */
#[Fillable(['national_id', 'full_name_ar', 'gender_id', 'marital_status_id', 'birth_date', 'birth_place'])]
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
            'birth_date' => 'date',
        ];
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id');
    }

    public function maritalStatus(): BelongsTo
    {
        return $this->belongsTo(MaritalStatus::class, 'marital_status_id');
    }

    public function employmentRelationships(): HasMany
    {
        return $this->hasMany(EmploymentRelationship::class, 'person_id');
    }
}
