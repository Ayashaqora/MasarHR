<?php

namespace Tests\Unit\Audit;

use App\Modules\Platform\Domain\CorrelationId;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * S04 §8: CorrelationId is a validated UUID, with no framework dependency, used purely as a
 * tracing identifier (AUD-09) — nothing in the codebase may branch a security decision on it.
 */
class CorrelationIdTest extends TestCase
{
    public function test_from_string_accepts_a_syntactically_valid_uuid(): void
    {
        $uuid = (string) Str::uuid7();

        $correlationId = CorrelationId::fromString($uuid);

        $this->assertNotNull($correlationId);
        $this->assertSame($uuid, (string) $correlationId);
    }

    public function test_from_string_accepts_any_rfc4122_uuid_version_not_only_v7(): void
    {
        // §8's HTTP-inbound policy: any syntactically valid UUID is accepted, matching what an
        // upstream proxy or another service might send as X-Correlation-ID.
        $uuidV4 = (string) Str::uuid();

        $this->assertNotNull(CorrelationId::fromString($uuidV4));
    }

    public function test_from_string_rejects_non_uuid_values(): void
    {
        $this->assertNull(CorrelationId::fromString('not-a-uuid'));
        $this->assertNull(CorrelationId::fromString(''));
        $this->assertNull(CorrelationId::fromString('12345'));
        $this->assertNull(CorrelationId::fromString('<script>alert(1)</script>'));
    }

    public function test_generate_produces_a_fresh_valid_uuid_each_call(): void
    {
        $a = CorrelationId::generate();
        $b = CorrelationId::generate();

        $this->assertTrue(Str::isUuid((string) $a));
        $this->assertTrue(Str::isUuid((string) $b));
        $this->assertNotSame((string) $a, (string) $b);
    }
}
