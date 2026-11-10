<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\InvalidSetupOptions;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class InvalidSetupOptionsTest extends UnitTestCase
{
    #[Test]
    public function isAnInvalidArgumentException(): void
    {
        $exception = new InvalidSetupOptions('Ungültige Option');

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame('Ungültige Option', $exception->getMessage());
    }
}
