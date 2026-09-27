<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Static enforcement of the MasarHR migration conventions (docs/database-persistence-foundation.md).
 * It scans the active migration directory, so every future stage's migrations are checked automatically.
 * The deliberately deferred framework skeleton migrations live in a sub-directory and are not scanned.
 */
class MigrationDisciplineTest extends TestCase
{
    /** @return list<string> */
    private static function activeMigrations(): array
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        sort($files);

        return $files;
    }

    /** Code with comments removed, so the rules only judge what actually executes. */
    private static function code(string $file): string
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $code = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return array<string, array{string}> */
    public static function migrations(): array
    {
        $cases = [];
        foreach (self::activeMigrations() as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    public function test_there_are_active_migrations_to_check(): void
    {
        $this->assertNotEmpty(self::activeMigrations());
    }

    #[DataProvider('migrations')]
    public function test_migration_file_name_is_timestamped_and_snake_case(string $file): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/', basename($file));
    }

    #[DataProvider('migrations')]
    public function test_migration_never_uses_cascade_or_wipes_data(string $file): void
    {
        $code = self::code($file);

        $this->assertDoesNotMatchRegularExpression('/\bCASCADE\b/i', $code, 'no CASCADE in migrations');
        $this->assertDoesNotMatchRegularExpression('/\bdropAllTables\b|\bdropAllViews\b|\bdropAllTypes\b|\bTRUNCATE\b/i', $code);
        $this->assertDoesNotMatchRegularExpression('/\bDROP\s+DATABASE\b/i', $code);
    }

    #[DataProvider('migrations')]
    public function test_migration_does_not_use_sqlite_or_sqlite_only_constructs(string $file): void
    {
        $this->assertDoesNotMatchRegularExpression('/sqlite/i', self::code($file));
    }

    #[DataProvider('migrations')]
    public function test_migration_uses_uuid_keys_and_timezone_aware_timestamps(string $file): void
    {
        $code = self::code($file);

        // Auto-increment technical identity is prohibited for MasarHR aggregates (UUID policy).
        $this->assertDoesNotMatchRegularExpression(
            '/->(id|increments|bigIncrements|mediumIncrements|smallIncrements|tinyIncrements|foreignId|foreignIdFor)\(/',
            $code,
            'use uuid() keys, not auto-increment ids',
        );

        // Recording timestamps must be timestamptz. Laravel timestamp()/timestamps()/dateTime() are "without time zone".
        $this->assertDoesNotMatchRegularExpression(
            '/->(timestamp|timestamps|dateTime|nullableTimestamps|softDeletes|rememberToken)\(/',
            $code,
            'use timestampTz()/timestampsTz()/dateTimeTz()/softDeletesTz()',
        );
    }

    #[DataProvider('migrations')]
    public function test_migration_contains_no_credentials(string $file): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/(password|secret|token|api[_-]?key)\s*[=:]\s*[\'"][^\'"]{4,}/i',
            self::code($file),
        );
    }

    #[DataProvider('migrations')]
    public function test_migration_declares_a_rollback(string $file): void
    {
        $this->assertMatchesRegularExpression('/function\s+down\s*\(/', self::code($file));
    }
}
