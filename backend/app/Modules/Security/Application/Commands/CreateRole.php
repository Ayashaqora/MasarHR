<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Security\Domain\Exceptions\DuplicateRoleCodeException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use Illuminate\Database\QueryException;

final class CreateRole
{
    /** @throws DuplicateRoleCodeException */
    public function handle(string $code, string $nameAr, string $nameEn, ?string $description): Role
    {
        $role = new Role([
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'description' => $description,
            'is_system' => false,
            'is_active' => true,
        ]);

        try {
            $role->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicateRoleCodeException;
            }

            throw $e;
        }

        return $role;
    }
}
