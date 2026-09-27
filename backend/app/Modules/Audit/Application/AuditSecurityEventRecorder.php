<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Application\Execution\CommandContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a SECURITY_EVENT independently of any mutation transaction boundary (S04 §14/D10). Every
 * call opens its own, freestanding transaction — never the caller's — so it can persist a
 * rejection even after the transaction that rejected it has already rolled back (call this only
 * after that rollback has happened — see call sites in the retrofitted Security controllers and
 * middleware). A failure to persist is swallowed (AUD-16): it never masks, replaces, or reopens
 * the security decision it is describing — the caller's original response is unaffected either
 * way.
 */
final class AuditSecurityEventRecorder
{
    public function __construct(private readonly AuditAppendService $appendService) {}

    public function record(
        CommandContext $context,
        string $action,
        string $targetType,
        ?string $targetId,
        Outcome $outcome,
        array $metadata = [],
    ): void {
        try {
            DB::transaction(function () use ($context, $action, $targetType, $targetId, $outcome, $metadata): void {
                $this->appendService->appendSecurityEvent($context, $action, $targetType, $targetId, $outcome, $metadata);
            });
        } catch (Throwable $e) {
            // AUD-16: never let a secondary recording failure mask, replace, or retroactively
            // affect the original security decision.
            Log::warning('Failed to persist SECURITY_EVENT audit entry.', [
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
