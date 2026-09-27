<?php

namespace App\Modules\Audit\Infrastructure\Persistence\Eloquent;

use App\Modules\Audit\Domain\Category;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Domain\ActorType;
use App\Modules\Platform\Domain\Source;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * audit.audit_entries — append-only (S04 §12). This model is never called with ->update() or
 * ->delete() anywhere in the codebase; PostgreSQL itself also rejects both directly (see the
 * audit_entries_immutable trigger added by the following migration), so even a future application
 * mistake here is still rejected at the database.
 *
 * $timestamps is disabled: occurred_at is the one and only timestamp column, set explicitly by
 * AuditAppendService — not Eloquent's created_at/updated_at convention.
 */
#[Fillable([
    'id', 'occurred_at', 'category', 'action', 'actor_type', 'actor_principal_id', 'actor_label',
    'source', 'correlation_id', 'target_type', 'target_id', 'outcome', 'changes', 'metadata',
])]
class AuditEntry extends Model
{
    protected $table = 'audit.audit_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'category' => Category::class,
            'actor_type' => ActorType::class,
            'source' => Source::class,
            'outcome' => Outcome::class,
            'changes' => 'array',
            'metadata' => 'array',
        ];
    }
}
