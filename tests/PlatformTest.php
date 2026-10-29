<?php

declare(strict_types=1);

namespace WilyCode\Database\DriverPdo\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use WilyCode\Database\Driver\PlatformProviderInterface;
use WilyCode\Database\DriverPdo\PdoDatabase;

final class PlatformTest extends TestCase
{
    public function testPlatformComesFromConnection(): void
    {
        if (!extension_loaded('pdo_sqlite')) { self::markTestSkipped('pdo_sqlite required.'); }
        $database = new PdoDatabase(new PDO('sqlite::memory:'));
        self::assertInstanceOf(PlatformProviderInterface::class, $database);
        self::assertSame('sqlite', $database->getPlatformName());
    }
}
