<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Base class for S04 Audit feature tests. Inherits SecurityTestCase's fixture helpers
 * (createPrincipal/createRoleWithPermissions/createSecurityAdministrator/assignRole) and adds
 * audit.audit_entries read helpers. id is a UUIDv7 (time-ordered), so ordering by id descending is
 * an exact, non-flaky "most recent" ordering even within the same millisecond.
 */
abstract class AuditTestCase extends SecurityTestCase
{
    protected function latestAuditEntry(): ?AuditEntry
    {
        return AuditEntry::query()->orderByDesc('id')->first();
    }

    protected function latestAuditEntryFor(string $action): ?AuditEntry
    {
        return AuditEntry::query()->where('action', $action)->orderByDesc('id')->first();
    }

    protected function auditEntriesCount(): int
    {
        return AuditEntry::query()->count();
    }
}
