<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * One of the seven structural weekday identities (S29 — docs/work-schedule-foundation-specification.md
 * §S29.5, ADR-S29-002). Read-only reference: seeded by migration, never administered, never
 * deleted. `code` (MONDAY…SUNDAY) is the only business identifier; name_ar/name_en are display
 * labels and iso_day_number is display ordering only.
 */
class Weekday extends Model
{
    public const CODES = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    protected $table = 'ref.weekdays';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['iso_day_number' => 'integer'];
    }
}
