<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Domain\Exceptions\DuplicateReferenceCodeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Shared body for the four simple reference-value families (Gender, MaritalStatus, DecisionType,
 * EmploymentStatusCategory — S05 §14/D3). Every call site still names an explicit, statically
 * resolvable command class (see CreateGender etc. below); this base only factors out the
 * identical create logic, exactly as PostgresErrorClassifier factors out SQLSTATE parsing without
 * becoming a generic service. Never resolved or dispatched by name.
 */
abstract class AbstractCreateSimpleReferenceValue
{
    abstract protected function modelClass(): string;

    /** @throws DuplicateReferenceCodeException */
    public function handle(string $code, string $nameAr, ?string $nameEn, ?int $displayOrder): Model
    {
        $class = $this->modelClass();

        /** @var Model $value */
        $value = new $class([
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'display_order' => $displayOrder ?? 0,
            'is_active' => true,
        ]);

        try {
            $value->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicateReferenceCodeException;
            }

            throw $e;
        }

        return $value;
    }
}
