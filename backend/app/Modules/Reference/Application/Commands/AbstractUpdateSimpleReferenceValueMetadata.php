<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Domain\Exceptions\StaleVersionException;
use Illuminate\Database\Eloquent\Model;

/** Shared body for the four simple reference-value families (S05 §14/D3). */
abstract class AbstractUpdateSimpleReferenceValueMetadata
{
    abstract protected function modelClass(): string;

    /** @throws StaleVersionException */
    public function handle(Model $value, string $nameAr, ?string $nameEn, ?int $displayOrder, int $expectedVersion): Model
    {
        $class = $this->modelClass();

        $updated = $class::query()
            ->where('id', $value->getKey())
            ->where('version', $expectedVersion)
            ->update([
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'display_order' => $displayOrder ?? $value->display_order,
                'version' => $expectedVersion + 1,
            ]);

        if ($updated === 0) {
            $class::query()->where('id', $value->getKey())->firstOrFail();

            throw new StaleVersionException;
        }

        return $value->refresh();
    }
}
