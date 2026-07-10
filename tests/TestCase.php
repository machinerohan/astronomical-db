<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use PDO;

abstract class TestCase extends BaseTestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = \test_pdo();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->pdo->rollBack();
        parent::tearDown();
    }

    protected function assertHtmlContains(string $haystack, string $needle, string $message = ''): void
    {
        $this->assertStringContainsString($needle, $haystack, $message);
    }

    protected function captureOutput(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean();
    }
}
