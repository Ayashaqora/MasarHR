<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Domain\Exceptions\StaleVersionException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared body for the four simple reference-value families (S05 §14/D3). No transaction of its
 * own — mirrors ActivateRole exactly (ERRATA-02 rationale): the scoped conditional UPDATE is
 * already atomic on its own, and AuditedCommandExecutor owns the one effective transaction.
 */
abstract class AbstractActivateSimpleReferenceValue
{
    abstract protected function modelClass(): string;

    /** @throws StaleVersionException */
    public function handle(Model $value, int $expectedVersion): Model
    {
        $class = $this->modelClass();

        $updated = $class::query()
            ->where('id', $value->getKey())
            ->where('version', $expectedVersion)
            ->update(['is_active' => true, 'version' => $expectedVersion + 1]);

        if ($updated === 0) {
            $class::query()->where('id', $value->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $value->refresh();
    }
}
