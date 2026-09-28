<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonProfileException;
use App\Modules\HumanResources\Domain\Exceptions\PersonStaleVersionException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use InvalidArgumentException;

/**
 * Explicitly updates a Person's CURRENT profile (docs/person-profile-foundation-specification.md
 * §S24.8, ADR-S24-001). It can change ONLY full_name_ar, gender, marital status, birth_date and
 * birth_place — never national_id, is_terminal, any employee number, or anything owned by an
 * Employment Relationship. Any subset of those five may be supplied; unsupplied fields are left
 * exactly as they are (a legacy NULL stays NULL until explicitly provided). full_name_ar, gender,
 * marital status and birth_date can be set or changed but never cleared back to unknown;
 * birth_place (optional) may be cleared with null.
 *
 * Optimistic-concurrency guarded, then no-op aware (CA-S24-01): the current row is loaded and
 * expected_version is checked FIRST — a stale caller gets PersonStaleVersionException (409) even
 * when its values happen to equal the stored ones — then every supplied value is validated and
 * normalized with the existing S24 rules only, then compared with the stored value. A request
 * whose normalized values all equal the stored profile is a NO-OP: no UPDATE, no version
 * increment, no timestamp change; actualChanges() returns [] so the caller writes no audit entry.
 * Otherwise a single scoped UPDATE ... WHERE id = ? AND version = ? applies only the genuinely
 * changed columns and increments version, so a rejected or stale request mutates nothing. There is
 * no history table — the immutable S04 audit entry written around this command is the change
 * record.
 */
final class UpdatePersonProfile
{
    public const FIELDS = ['full_name_ar', 'gender', 'marital_status', 'birth_date', 'birth_place'];

    public function __construct(private readonly PersonProfileValidator $profile) {}

    /**
     * The profile columns this request would ACTUALLY change, after the expected_version check and
     * the existing S24 validation/normalization — [] for a semantic no-op.
     *
     * @param  array{full_name_ar?: string, gender?: Gender, marital_status?: MaritalStatus, birth_date?: string, birth_place?: ?string}  $changes
     * @return array<string, ?string>
     *
     * @throws InvalidPersonProfileException|PersonStaleVersionException
     */
    public function actualChanges(Person $person, int $expectedVersion, array $changes): array
    {
        $unknown = array_diff(array_keys($changes), self::FIELDS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('UpdatePersonProfile cannot change: '.implode(', ', $unknown));
        }

        $current = Person::query()->where('id', $person->getKey())->firstOrFail();

        if ($current->version !== $expectedVersion) {
            throw new PersonStaleVersionException;
        }

        if ($changes === []) {
            throw new InvalidPersonProfileException('profile', 'At least one profile field must be supplied.');
        }

        $columns = [];

        if (array_key_exists('full_name_ar', $changes)) {
            $columns['full_name_ar'] = $this->profile->fullNameAr($changes['full_name_ar']);
        }
        if (array_key_exists('gender', $changes)) {
            $columns['gender_id'] = $this->profile->activeGender($changes['gender'])->getKey();
        }
        if (array_key_exists('marital_status', $changes)) {
            $columns['marital_status_id'] = $this->profile->activeMaritalStatus($changes['marital_status'])->getKey();
        }
        if (array_key_exists('birth_date', $changes)) {
            $columns['birth_date'] = $this->profile->birthDate($changes['birth_date']);
        }
        if (array_key_exists('birth_place', $changes)) {
            $columns['birth_place'] = $this->profile->birthPlace($changes['birth_place']);
        }

        $stored = [
            'full_name_ar' => $current->full_name_ar,
            'gender_id' => $current->gender_id,
            'marital_status_id' => $current->marital_status_id,
            'birth_date' => $current->birth_date?->toDateString(),
            'birth_place' => $current->birth_place,
        ];

        return array_filter($columns, fn (?string $value, string $column) => $stored[$column] !== $value, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array{full_name_ar?: string, gender?: Gender, marital_status?: MaritalStatus, birth_date?: string, birth_place?: ?string}  $changes
     *
     * @throws InvalidPersonProfileException|PersonStaleVersionException
     */
    public function handle(Person $person, int $expectedVersion, array $changes): Person
    {
        $columns = $this->actualChanges($person, $expectedVersion, $changes);

        if ($columns === []) {
            return Person::query()->where('id', $person->getKey())->firstOrFail();
        }

        $updated = Person::query()
            ->where('id', $person->getKey())
            ->where('version', $expectedVersion)
            ->update([...$columns, 'version' => $expectedVersion + 1, 'updated_at' => now()]);

        if ($updated === 0) {
            Person::query()->where('id', $person->getKey())->firstOrFail();

            throw new PersonStaleVersionException;
        }

        return Person::query()->where('id', $person->getKey())->firstOrFail();
    }
}
