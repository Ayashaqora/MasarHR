<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Domain\Exceptions\DuplicateReferenceCodeException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use Illuminate\Database\QueryException;

/**
 * Not a subclass of AbstractCreateSimpleReferenceValue: unlike Gender/MaritalStatus/DecisionType/
 * EmploymentStatusCategory, creating an employment status detail additionally requires
 * category_id, which the shared base's signature does not carry (S05 §14). category_id is
 * immutable after creation — there is no "reassign category" command in S05 (§19).
 */
final class CreateEmploymentStatusDetail
{
    /** @throws DuplicateReferenceCodeException */
    public function handle(string $categoryId, string $code, string $nameAr, ?string $nameEn, ?int $displayOrder): EmploymentStatusDetail
    {
        $detail = new EmploymentStatusDetail([
            'category_id' => $categoryId,
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'display_order' => $displayOrder ?? 0,
            'is_active' => true,
        ]);

        try {
            $detail->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicateReferenceCodeException;
            }

            throw $e;
        }

        return $detail;
    }
}
