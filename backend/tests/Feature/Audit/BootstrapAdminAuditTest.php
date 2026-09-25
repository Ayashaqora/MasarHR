<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Domain\Category;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Domain\ActorType;
use App\Modules\Platform\Domain\Source;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;

/**
 * S04 §19 retrofit: BootstrapAdminCommand writes exactly two MUTATION audit entries — principal
 * creation (CreatePrincipal+SetInitialPassword aggregated, ERRATA-04 extended to this CLI
 * workflow) and the role assignment — with actor SYSTEM/CLI_BOOTSTRAP and source CLI, inside its
 * own pre-existing single transaction (BOOT-04). No SYSTEM Principal row is ever created.
 */
class BootstrapAdminAuditTest extends AuditTestCase
{
    public function test_bootstrap_writes_exactly_two_mutation_entries_with_the_cli_bootstrap_actor(): void
    {
        $countBefore = $this->auditEntriesCount();

        $this->artisan('masar:security:bootstrap-admin')
            ->expectsQuestion('Administrator username', 'auditbootstrap')
            ->expectsQuestion('Display name', 'Audit Bootstrap Admin')
            ->expectsQuestion('Password (input hidden)', 'ABootstrapPassphrase1!')
            ->expectsQuestion('Confirm password', 'ABootstrapPassphrase1!')
            ->assertExitCode(0);

        $principal = Principal::query()->where('username_normalized', 'auditbootstrap')->firstOrFail();

        $this->assertSame($countBefore + 2, $this->auditEntriesCount());

        $createEntry = $this->latestAuditEntryFor('security.principal.create');
        $this->assertNotNull($createEntry);
        $this->assertSame(Category::Mutation, $createEntry->category);
        $this->assertSame(Outcome::Succeeded, $createEntry->outcome);
        $this->assertSame(ActorType::System, $createEntry->actor_type);
        $this->assertSame('CLI_BOOTSTRAP', $createEntry->actor_label);
        $this->assertNull($createEntry->actor_principal_id);
        $this->assertSame(Source::Cli, $createEntry->source);
        $this->assertSame($principal->getKey(), $createEntry->target_id);
        $this->assertArrayNotHasKey('password', $createEntry->changes ?? []);

        $assignEntry = $this->latestAuditEntryFor('security.role_assignment.create');
        $this->assertNotNull($assignEntry);
        $this->assertSame(ActorType::System, $assignEntry->actor_type);
        $this->assertSame('CLI_BOOTSTRAP', $assignEntry->actor_label);
        $this->assertSame(Source::Cli, $assignEntry->source);
        $this->assertStringStartsWith($principal->getKey().':', $assignEntry->target_id);

        // Both entries share one correlation id — one invocation, one logical operation.
        $this->assertSame($createEntry->correlation_id, $assignEntry->correlation_id);
    }

    public function test_bootstrap_never_creates_a_system_principal_row(): void
    {
        $this->artisan('masar:security:bootstrap-admin')
            ->expectsQuestion('Administrator username', 'nosystemprincipal')
            ->expectsQuestion('Display name', 'No System Principal')
            ->expectsQuestion('Password (input hidden)', 'ABootstrapPassphrase1!')
            ->expectsQuestion('Confirm password', 'ABootstrapPassphrase1!')
            ->assertExitCode(0);

        // Exactly the one HUMAN principal was created; SYSTEM actors never have a Principal row.
        $this->assertSame(1, Principal::query()->where('username_normalized', 'nosystemprincipal')->count());

        $createEntry = $this->latestAuditEntryFor('security.principal.create');
        $this->assertNull($createEntry->actor_principal_id);
    }
}
