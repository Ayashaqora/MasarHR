<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Domain\Exceptions\StaleVersionException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared body for the four simple reference-value families (S05 §14/D3). No transaction of its
 * own (ERRATA-02 rationale, same as AbstractActivateSimpleReferenceValue). Deactivation only —
 * no S05 command ever hard-deletes a reference value (§9).
 */
abstract class AbstractDeactivateSimpleReferenceValue
{
    abstract protected function modelClass(): string;

    /** @throws StaleVersionException */
    public function handle(Model $value, int $expectedVersion): Model
    {
        $class = $this->modelClass();

        $updated = $class::query()
            ->where('id', $value->getKey())
            ->where('version', $expectedVersion)
            ->update(['is_active' => false, 'version' => $expectedVersion + 1]);

        if ($updated === 0) {
            $class::query()->where('id', $value->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $value->refresh();
    }
}
