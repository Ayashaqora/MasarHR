<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.3/§S48.13, D13): a
 * read-only Eloquent model backed by the `hr.person_qualifications_current` VIEW — the one read
 * path every consumer repoints to. Never written through: the view has no identity of its own to
 * write, and `$timestamps` is disabled because this is not the owning table of `created_at`.
 * Carries exactly the columns the view selects: the qualification's stable identity fields
 * (`id`, `person_id`, `is_primary`, `created_at`) plus its current version's own values
 * (`academic_degree_id`, `qualification_type_id`, `obtained_on`, `version_number`).
 */
class PersonQualificationCurrent extends Model
{
    protected $table = 'hr.person_qualifications_current';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'obtained_on' => 'date:Y-m-d',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}
