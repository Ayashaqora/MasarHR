<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Cheap, connection-free safety check for every test: PostgreSQL + isolated test database only.
        TestDatabaseGuard::assertConfigured();
    }
}
