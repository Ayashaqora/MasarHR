<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\HumanResources\Application\Commands\CreatePerson;
use App\Modules\HumanResources\Application\Commands\UpdatePersonProfile;
use App\Modules\HumanResources\Application\Queries\FindPersonByNationalId;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Presentation\Http\Resources\PersonResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S09 Person administration surface (spec §19). Audit payload never duplicates national_id
 * outside hr.persons itself (spec §18) — only the created/found Person's id is recorded.
 *
 * S24 (docs/person-profile-foundation-specification.md §S24.6/§S24.8/§S24.13): creation now also
 * takes the required profile, and profile changes go through one explicit action route
 * (POST .../update-profile) — no generic PATCH. Audit payloads for both record stable reference
 * ids and the NAMES of the profile fields set/changed, never the name, birth date or birth place
 * values themselves (§S24.14).
 */
class PersonController
{
    public function store(Request $request, CreatePerson $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'national_id' => ['required', 'string', 'max:64'],
            'full_name_ar' => ['required', 'string', 'max:255'],
            'gender_id' => ['required', 'uuid'],
            'marital_status_id' => ['required', 'uuid'],
            'birth_date' => ['required', 'date'],
            'birth_place' => ['nullable', 'string', 'max:255'],
        ]);

        $gender = $this->findOrFail(Gender::class, $data['gender_id'], 'Gender not found.');
        $maritalStatus = $this->findOrFail(MaritalStatus::class, $data['marital_status_id'], 'Marital status not found.');

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'hr.person.create',
            targetType: 'hr_person',
            targetId: fn (Person $created) => $created->getKey(),
            changes: fn (Person $created) => [
                'gender_id' => $created->gender_id,
                'marital_status_id' => $created->marital_status_id,
            ],
            metadata: fn (Person $created) => [
                'profile_fields_set' => array_values(array_filter(
                    ['full_name_ar', 'gender_id', 'marital_status_id', 'birth_date', 'birth_place'],
                    fn (string $field) => $created->{$field} !== null,
                )),
            ],
        );

        $person = $executor->run($context, $spec, fn (): Person => $command->handle(
            $data['national_id'],
            $data['full_name_ar'],
            $gender,
            $maritalStatus,
            $data['birth_date'],
            $data['birth_place'] ?? null,
        ));

        return (new PersonResource($person))->response()->setStatusCode(201);
    }

    public function updateProfile(
        Request $request,
        Person $person,
        UpdatePersonProfile $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'full_name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'gender_id' => ['sometimes', 'required', 'uuid'],
            'marital_status_id' => ['sometimes', 'required', 'uuid'],
            'birth_date' => ['sometimes', 'required', 'date'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $changes = [];
        if (array_key_exists('full_name_ar', $data)) {
            $changes['full_name_ar'] = $data['full_name_ar'];
        }
        if (array_key_exists('gender_id', $data)) {
            $changes['gender'] = $this->findOrFail(Gender::class, $data['gender_id'], 'Gender not found.');
        }
        if (array_key_exists('marital_status_id', $data)) {
            $changes['marital_status'] = $this->findOrFail(MaritalStatus::class, $data['marital_status_id'], 'Marital status not found.');
        }
        if (array_key_exists('birth_date', $data)) {
            $changes['birth_date'] = $data['birth_date'];
        }
        if (array_key_exists('birth_place', $data)) {
            $changes['birth_place'] = $data['birth_place'];
        }

        $expectedVersion = (int) $data['expected_version'];

        // CA-S24-01: a semantically unchanged request (after the stale-version check and S24's own
        // validation/normalization) is a NO-OP — no UPDATE, no version increment and no audit
        // entry, so it never enters the audited executor. Every Person mutation increments
        // version, so the version-guarded UPDATE inside handle() still rejects any concurrent
        // change made between this check and the write.
        if ($command->actualChanges($person, $expectedVersion, $changes) === []) {
            return (new PersonResource($person->fresh()))->response();
        }

        $before = $person->only(['full_name_ar', 'gender_id', 'marital_status_id', 'birth_date', 'birth_place']);
        $before['birth_date'] = $person->birth_date?->toDateString();

        $spec = new AuditSpec(
            action: 'hr.person.profile.update',
            targetType: 'hr_person',
            targetId: fn (Person $updated) => $updated->getKey(),
            changes: function (Person $updated) use ($before) {
                $changes = [];
                foreach (['gender_id', 'marital_status_id'] as $reference) {
                    if ($before[$reference] !== $updated->{$reference}) {
                        $changes[$reference] = ['from' => $before[$reference], 'to' => $updated->{$reference}];
                    }
                }

                return $changes;
            },
            metadata: function (Person $updated) use ($before) {
                $after = [
                    'full_name_ar' => $updated->full_name_ar,
                    'gender_id' => $updated->gender_id,
                    'marital_status_id' => $updated->marital_status_id,
                    'birth_date' => $updated->birth_date?->toDateString(),
                    'birth_place' => $updated->birth_place,
                ];

                return ['changed_fields' => array_values(array_keys(array_filter(
                    $after,
                    fn ($value, string $field) => $before[$field] !== $value,
                    ARRAY_FILTER_USE_BOTH,
                )))];
            },
        );

        $updated = $executor->run(
            ResolveCommandContext::from($request),
            $spec,
            fn (): Person => $command->handle($person, $expectedVersion, $changes),
        );

        return (new PersonResource($updated))->response();
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

    /** Existence only (404, the S16/S20–S23 reference-lookup convention); activeness is a command rule. */
    private function findOrFail(string $modelClass, string $id, string $message): Gender|MaritalStatus
    {
        return $modelClass::query()->find($id) ?? throw new NotFoundHttpException($message);
    }
}
