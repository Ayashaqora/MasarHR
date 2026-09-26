<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\CreatePerson;
use App\Modules\HumanResources\Application\Queries\FindPersonByNationalId;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\PersonResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S09 Person administration surface (spec §19). Audit payload never duplicates national_id
 * outside hr.persons itself (spec §18) — only the created/found Person's id is recorded.
 */
class PersonController
{
    public function store(Request $request, CreatePerson $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'national_id' => ['required', 'string', 'max:64'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'hr.person.create',
            targetType: 'hr_person',
            targetId: fn (Person $created) => $created->getKey(),
            changes: fn () => [],
            metadata: fn () => [],
        );

        $person = $executor->run($context, $spec, fn (): Person => $command->handle($data['national_id']));

        return (new PersonResource($person))->response()->setStatusCode(201);
    }

    public function show(Person $person): JsonResponse
    {
        return (new PersonResource($person))->response();
    }

    public function lookup(Request $request, FindPersonByNationalId $query): JsonResponse
    {
        $data = $request->validate([
            'national_id' => ['required', 'string', 'max:64'],
        ]);

        $person = $query($data['national_id']);

        if ($person === null) {
            throw new NotFoundHttpException('No person found for this national ID.');
        }

        return (new PersonResource($person))->response();
    }
}
