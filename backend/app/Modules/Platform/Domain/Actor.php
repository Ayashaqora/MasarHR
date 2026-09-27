<?php

namespace App\Modules\Platform\Domain;

/**
 * Who performed or attempted a command (S04 §7). HUMAN always carries a real security.principals
 * UUID and no label; SYSTEM never carries a principal id and instead carries a controlled,
 * application-chosen label — never client-supplied (see the calling middleware/CLI entry point).
 *
 * ERRATA-01: a SYSTEM actor with label UNAUTHENTICATED is audit provenance only. It never implies
 * that the external caller is trusted, has SYSTEM authority, or may invoke a SYSTEM-only
 * capability — no authorization decision anywhere in the codebase reads this value.
 */
final class Actor
{
    private function __construct(
        public readonly ActorType $type,
        public readonly ?string $principalId,
        public readonly ?string $label,
    ) {}

    public static function human(string $principalId): self
    {
        return new self(ActorType::Human, $principalId, null);
    }

    public static function system(string $label): self
    {
        return new self(ActorType::System, null, $label);
    }
}
