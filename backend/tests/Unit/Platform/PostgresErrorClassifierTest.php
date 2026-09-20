<?php

namespace Tests\Unit\Platform;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Classifier;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PostgresErrorClassifierTest extends TestCase
{
    private static function pdo(string $sqlState): PDOException
    {
        $exception = new PDOException('simulated');
        $exception->errorInfo = [$sqlState, 0, 'simulated'];

        return $exception;
    }

    private static function query(string $sqlState): QueryException
    {
        return new QueryException('pgsql', 'select 1', [], self::pdo($sqlState));
    }

    public function test_reads_the_sqlstate_from_the_pdo_exception_inside_a_query_exception(): void
    {
        $this->assertSame('23505', Classifier::sqlState(self::query('23505')));
    }

    public function test_reads_the_sqlstate_from_a_wrapped_exception_chain(): void
    {
        $wrapped = new RuntimeException('outer', 0, new RuntimeException('middle', 0, self::pdo('40001')));

        $this->assertSame('40001', Classifier::sqlState($wrapped));
    }

    public function test_returns_null_when_there_is_no_sqlstate(): void
    {
        $this->assertNull(Classifier::sqlState(new RuntimeException('plain')));
        $this->assertFalse(Classifier::isRetryable(new RuntimeException('plain')));
    }

    /** @return array<string, array{string, string}> state => classifier method */
    public static function classifications(): array
    {
        return [
            'unique' => ['23505', 'isUniqueViolation'],
            'exclusion' => ['23P01', 'isExclusionViolation'],
            'check' => ['23514', 'isCheckViolation'],
            'foreign key' => ['23503', 'isForeignKeyViolation'],
            'not null' => ['23502', 'isNotNullViolation'],
            'lock not available' => ['55P03', 'isLockNotAvailable'],
            'serialization' => ['40001', 'isSerializationFailure'],
            'deadlock' => ['40P01', 'isDeadlock'],
        ];
    }

    #[DataProvider('classifications')]
    public function test_each_sqlstate_is_recognised_by_exactly_its_own_classifier(string $state, string $method): void
    {
        $error = self::query($state);

        foreach (array_column(self::classifications(), 1) as $candidate) {
            $this->assertSame($candidate === $method, Classifier::$candidate($error), "{$candidate} for {$state}");
        }
    }

    #[DataProvider('retryability')]
    public function test_only_serialization_failures_and_deadlocks_are_retryable(string $state, bool $retryable): void
    {
        $this->assertSame($retryable, Classifier::isRetryable(self::query($state)));
    }

    /** @return array<string, array{string, bool}> */
    public static function retryability(): array
    {
        return [
            'serialization failure' => ['40001', true],
            'deadlock' => ['40P01', true],
            'unique violation' => ['23505', false],
            'exclusion violation' => ['23P01', false],
            'check violation' => ['23514', false],
            'lock not available' => ['55P03', false],
            'syntax error' => ['42601', false],
        ];
    }
}
