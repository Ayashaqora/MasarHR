<?php

namespace App\Modules\Audit\Domain;

use Closure;

/**
 * Describes, for one call site, what a successful MUTATION audit entry should contain (S04 §9).
 * `changes`/`metadata` are derived from the command's result by closures the call site supplies —
 * already redacted to that action's allowlist (§15); AuditAppendService never inspects the
 * command result itself, only what these closures return.
 */
final class AuditSpec
{
    /**
     * @param  string  $action  stable dotted action code (§16/§17 naming convention)
     * @param  string  $targetType  stable snake_case target type code
     * @param  Closure(mixed): ?string  $targetId  derives target_id from the command result
     * @param  Closure(mixed): ?array<string, mixed>  $changes  derives the allowlisted `changes` payload
     * @param  Closure(mixed): array<string, mixed>  $metadata  derives the allowlisted `metadata` payload
     */
    public function __construct(
        public readonly string $action,
        public readonly string $targetType,
        public readonly Closure $targetId,
        public readonly Closure $changes,
        public readonly Closure $metadata,
    ) {}
}
