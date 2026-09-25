<?php

namespace App\Modules\Platform\Application\Execution;

use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;

/**
 * Who/what triggered this, and how requests correlate — independent of HTTP or Laravel Auth (S04
 * §6). Resolved once per request/invocation (HTTP middleware or a CLI entry point) and passed
 * through; contains no reference to Illuminate\Http\Request or Illuminate\Support\Facades\Auth, so
 * it is constructible in a unit test with no framework bootstrap.
 */
final class CommandContext
{
    public function __construct(
        public readonly Actor $actor,
        public readonly CorrelationId $correlationId,
        public readonly Source $source,
    ) {}
}
