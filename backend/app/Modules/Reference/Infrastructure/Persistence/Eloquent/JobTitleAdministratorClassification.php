<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Effective-dated classification of a JobTitle as "administrator" (Report 2, "الإداريين") or not
 * (S06 spec §12.3). Append-only, matching EmploymentStatusDetailBehavior exactly.
 */
#[Fillable(['job_title_id', 'is_administrator', 'effective_from', 'effective_to'])]
class JobTitleAdministratorClassification extends Model
{
    protected $table = 'ref.job_title_administrator_classifications';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $value): void {
            if (! $value->getKey()) {
                $value->{$value->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_administrator' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }
}
