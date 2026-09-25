<?php

namespace Tests\Unit\Security;

use App\Modules\Security\Domain\UsernameNormalizer;
use PHPUnit\Framework\TestCase;

class UsernameNormalizerTest extends TestCase
{
    public function test_lowercases_and_trims(): void
    {
        $this->assertSame('admin', UsernameNormalizer::normalize('  Admin  '));
        $this->assertSame('admin', UsernameNormalizer::normalize('ADMIN'));
        $this->assertSame('admin', UsernameNormalizer::normalize('admin'));
    }

    public function test_admin_and_lowercase_admin_normalize_identically(): void
    {
        $this->assertSame(UsernameNormalizer::normalize('Admin'), UsernameNormalizer::normalize('admin'));
    }

    public function test_internal_whitespace_is_preserved(): void
    {
        $this->assertSame('a b', UsernameNormalizer::normalize(' A B '));
    }
}
