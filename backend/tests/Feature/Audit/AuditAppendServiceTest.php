<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Application\AuditAppendService;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Audit\Domain\Category;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use RuntimeException;

/** S04 §12/§15: AuditAppendService is the only write path into audit.audit_entries. */
class AuditAppendServiceTest extends AuditTestCase
{
    public function test_append_mutation_writes_a_succeeded_mutation_entry_with_every_field(): void
    {
        $admin = $this->createSecurityAdministrator();
        $service = app(AuditAppendService::class);
        $context = new CommandContext(Actor::human($admin->getKey()), CorrelationId::generate(), Source::Http);

        $spec = new AuditSpec(
            action: 'test.mutation.append',
            targetType: 'security_principal',
            targetId: fn (string $result) => $result,
            changes: fn (string $result) => ['probe' => $result],
            metadata: fn () => ['note' => 'ok'],
        );

        $entry = $service->appendMutation($context, $spec, 'probe-result');

        $this->assertSame(Category::Mutation, $entry->category);
        $this->assertSame('test.mutation.append', $entry->action);
        $this->assertSame(Outcome::Succeeded, $entry->outcome);
        $this->assertSame('security_principal', $entry->target_type);
        $this->assertSame('probe-result', $entry->target_id);
        $this->assertSame($admin->getKey(), $entry->actor_principal_id);
        $this->assertNull($entry->actor_label);
        $this->assertSame(Source::Http, $entry->source);
        $this->assertSame((string) $context->correlationId, $entry->correlation_id);
        $this->assertSame(['probe' => 'probe-result'], $entry->changes);
        $this->assertSame(['note' => 'ok'], $entry->metadata);
        $this->assertNotNull($entry->occurred_at);
    }

    public function test_append_security_event_writes_the_given_outcome_and_category(): void
    {
        $service = app(AuditAppendService::class);
        $context = new CommandContext(Actor::system('TEST_PROBE'), CorrelationId::generate(), Source::Http);

        $entry = $service->appendSecurityEvent($context, 'test.security_event.append', 'security_principal', null, Outcome::Rejected, ['reason' => 'PROBE']);

        $this->assertSame(Category::SecurityEvent, $entry->category);
        $this->assertSame(Outcome::Rejected, $entry->outcome);
        $this->assertNull($entry->target_id);
        $this->assertNull($entry->changes);
        $this->assertSame(['reason' => 'PROBE'], $entry->metadata);
        $this->assertSame('SYSTEM', $entry->actor_type->value);
        $this->assertSame('TEST_PROBE', $entry->actor_label);
        $this->assertNull($entry->actor_principal_id);
    }

    public function test_append_security_event_stores_an_empty_metadata_array_as_null(): void
    {
        $service = app(AuditAppendService::class);
        $context = new CommandContext(Actor::system('TEST_PROBE'), CorrelationId::generate(), Source::Http);

        $entry = $service->appendSecurityEvent($context, 'test.security_event.empty_metadata', 'security_principal', null, Outcome::Succeeded);

        $this->assertNull($entry->metadata);
    }

    public function test_append_mutation_rejects_a_denylisted_key_in_changes_and_writes_nothing(): void
    {
        $admin = $this->createSecurityAdministrator();
        $service = app(AuditAppendService::class);
        $context = new CommandContext(Actor::human($admin->getKey()), CorrelationId::generate(), Source::Http);

        $spec = new AuditSpec(
            action: 'test.mutation.denylisted_changes',
            targetType: 'security_principal',
            targetId: fn () => $admin->getKey(),
            changes: fn () => ['password' => 'must-never-be-written'],
            metadata: fn () => [],
        );

        $countBefore = $this->auditEntriesCount();

        $this->expectException(RuntimeException::class);

        try {
            $service->appendMutation($context, $spec, null);
        } finally {
            $this->assertSame($countBefore, $this->auditEntriesCount(), 'a denylist violation must write nothing');
        }
    }

    public function test_append_security_event_rejects_a_denylisted_key_in_metadata(): void
    {
        $service = app(AuditAppendService::class);
        $context = new CommandContext(Actor::system('TEST_PROBE'), CorrelationId::generate(), Source::Http);

        $this->expectException(RuntimeException::class);

        $service->appendSecurityEvent($context, 'test.security_event.denylisted', 'security_principal', null, Outcome::Rejected, ['session_id' => 'abc']);
    }
}
