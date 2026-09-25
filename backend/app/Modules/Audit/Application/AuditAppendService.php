<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Audit\Domain\Category;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry as EloquentAuditEntry;
use App\Modules\Platform\Application\Execution\CommandContext;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The only write path into audit.audit_entries (S04 §12/§15). Input is already the narrow,
 * pre-built allowlisted `changes`/`metadata` produced by a call site's AuditSpec — never a raw
 * Request or Eloquent model. The denylist assertion below is the last-resort, defense-in-depth
 * check for an allowlist authored incorrectly; the allowlist itself, defined at each call site, is
 * the primary redaction mechanism (§15/D9).
 */
final class AuditAppendService
{
    /** Keys that must never appear in changes/metadata, whatever the allowlist says (§15 denylist). */
    private const DENYLIST = [
        'password', 'plaintext_password', 'old_password', 'new_password',
        'password_hash', 'session_id', 'session_cookie', 'csrf_token',
        'token', 'authentication_token', 'bearer_token', 'authorization',
        'secret', 'app_key', 'env',
    ];

    /** A successful MUTATION entry — outcome is always SUCCEEDED (§13). */
    public function appendMutation(CommandContext $context, AuditSpec $spec, mixed $result): EloquentAuditEntry
    {
        $changes = ($spec->changes)($result);
        $metadata = ($spec->metadata)($result);
        $targetId = ($spec->targetId)($result);

        $this->assertNoDeniedKeys($changes);
        $this->assertNoDeniedKeys($metadata);

        return $this->insert(
            context: $context,
            category: Category::Mutation,
            action: $spec->action,
            targetType: $spec->targetType,
            targetId: $targetId,
            outcome: Outcome::Succeeded,
            changes: $changes,
            metadata: $metadata,
        );
    }

    /** A SECURITY_EVENT entry — success or rejection, never implying a committed mutation (§14). */
    public function appendSecurityEvent(
        CommandContext $context,
        string $action,
        string $targetType,
        ?string $targetId,
        Outcome $outcome,
        array $metadata = [],
    ): EloquentAuditEntry {
        $this->assertNoDeniedKeys($metadata);

        return $this->insert(
            context: $context,
            category: Category::SecurityEvent,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            outcome: $outcome,
            changes: null,
            metadata: $metadata === [] ? null : $metadata,
        );
    }

    private function insert(
        CommandContext $context,
        Category $category,
        string $action,
        string $targetType,
        ?string $targetId,
        Outcome $outcome,
        ?array $changes,
        ?array $metadata,
    ): EloquentAuditEntry {
        $entry = new EloquentAuditEntry([
            'id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'category' => $category->value,
            'action' => $action,
            'actor_type' => $context->actor->type->value,
            'actor_principal_id' => $context->actor->principalId,
            'actor_label' => $context->actor->label,
            'source' => $context->source->value,
            'correlation_id' => (string) $context->correlationId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'outcome' => $outcome->value,
            'changes' => $changes,
            'metadata' => $metadata,
        ]);

        $entry->save();

        return $entry;
    }

    /** @param  array<string, mixed>|null  $payload */
    private function assertNoDeniedKeys(?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        foreach (array_keys($payload) as $key) {
            if (in_array(Str::lower((string) $key), self::DENYLIST, true)) {
                throw new RuntimeException("Audit payload key \"{$key}\" is denylisted and must never be written to audit.audit_entries.");
            }
        }
    }
}
