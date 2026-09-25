<?php

namespace App\Modules\Platform\Domain;

use Illuminate\Support\Str;

/**
 * A validated UUID used purely as a tracing identifier (S04 §8). It is never authentication or
 * authorization evidence — nothing in this codebase may branch a security decision on its value
 * (AUD-09). This class has no framework dependency; reading it from an HTTP header is the job of
 * the Presentation-layer resolution path (ResolveCommandContext), not this class.
 */
final class CorrelationId
{
    private function __construct(public readonly string $value) {}

    /** Accepts any syntactically valid UUID (any RFC 4122 version) — matches §8's HTTP-inbound policy. */
    public static function fromString(string $value): ?self
    {
        return Str::isUuid($value) ? new self($value) : null;
    }

    public static function generate(): self
    {
        return new self((string) Str::uuid7());
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
