<?php

namespace Tests\Unit\Security;

use App\Modules\Security\Domain\PasswordPolicy;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_rejects_a_password_below_the_minimum_length(): void
    {
        $this->assertFalse(PasswordPolicy::isValid(str_repeat('a', PasswordPolicy::MIN_LENGTH - 1)));
    }

    public function test_accepts_a_password_at_the_minimum_length(): void
    {
        $this->assertTrue(PasswordPolicy::isValid(str_repeat('a', PasswordPolicy::MIN_LENGTH)));
    }

    public function test_accepts_a_password_at_the_maximum_length(): void
    {
        $this->assertTrue(PasswordPolicy::isValid(str_repeat('a', PasswordPolicy::MAX_LENGTH)));
    }

    public function test_rejects_a_password_above_the_maximum_length(): void
    {
        $this->assertFalse(PasswordPolicy::isValid(str_repeat('a', PasswordPolicy::MAX_LENGTH + 1)));
    }

    public function test_violations_are_empty_for_a_valid_password(): void
    {
        $this->assertSame([], PasswordPolicy::violations(str_repeat('a', PasswordPolicy::MIN_LENGTH)));
    }

    public function test_violations_are_non_empty_for_an_invalid_password(): void
    {
        $this->assertNotSame([], PasswordPolicy::violations('short'));
    }
}
