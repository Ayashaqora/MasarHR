<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Application\Execution\CommandContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one small, explicit orchestration abstraction D11 permits (S04 §9/§11 of the authorization).
 * Owns the single outer transaction for an audited mutation: the command's mutation, its
 * invariant/concurrency checks (already inside $operation), and the successful audit append all
 * commit or roll back together (AUD-01/AUD-02). Not a dispatcher — the caller already constructs
 * the exact command and AuditSpec it is calling; this class routes, resolves, or dispatches
 * nothing by name or type.
 */
final class AuditedCommandExecutor
{
    public function __construct(private readonly AuditAppendService $appendService) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(CommandContext $context, AuditSpec $spec, Closure $operation): mixed
    {
        return DB::transaction(function () use ($context, $spec, $operation) {
            $result = $operation();

            $this->appendService->appendMutation($context, $spec, $result);

            return $result;
        });
    }
}
