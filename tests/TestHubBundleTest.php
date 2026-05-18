<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use TestHub\Bundle\TestHubBundle;

class TestHubBundleTest extends TestCase
{
    public function testBundleInstance(): void
    {
        $bundle = new TestHubBundle();
        $this->assertInstanceOf(TestHubBundle::class, $bundle);
    }
}
