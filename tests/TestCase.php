<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $database = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE'));

        if ($database !== 'manzomaerp_testing') {
            throw new \RuntimeException(
                "Refusing to run destructive tests against database [{$database}]. Expected [manzomaerp_testing]."
            );
        }

        parent::setUp();
    }
}
