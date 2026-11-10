<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\Finding;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FindingTest extends UnitTestCase
{
    #[Test]
    public function createsFindingWithAllProperties(): void
    {
        $finding = new Finding(
            Finding::WARNING,
            'test-code',
            'Something went wrong',
            'Here is how to fix it',
        );

        self::assertSame(Finding::WARNING, $finding->severity);
        self::assertSame('test-code', $finding->code);
        self::assertSame('Something went wrong', $finding->message);
        self::assertSame('Here is how to fix it', $finding->hint);
    }

    #[Test]
    public function createsFindingWithDefaultHint(): void
    {
        $finding = new Finding(Finding::OK, 'ok-code', 'Everything is good');

        self::assertSame(Finding::OK, $finding->severity);
        self::assertSame('ok-code', $finding->code);
        self::assertSame('Everything is good', $finding->message);
        self::assertSame('', $finding->hint);
    }

    #[Test]
    public function verifiesSeverityConstants(): void
    {
        self::assertSame('ok', Finding::OK);
        self::assertSame('info', Finding::INFO);
        self::assertSame('warning', Finding::WARNING);
        self::assertSame('error', Finding::ERROR);
    }
}
