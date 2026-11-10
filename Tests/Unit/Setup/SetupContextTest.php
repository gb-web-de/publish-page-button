<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\SetupContext;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SetupContextTest extends UnitTestCase
{
    #[Test]
    public function remembersAndReturnsGroups(): void
    {
        $context = new SetupContext();

        self::assertSame(0, $context->group('Unknown'));
        self::assertSame([], $context->groups());

        $context->rememberGroup('Redaktion', 12);
        self::assertSame(12, $context->group('Redaktion'));
        self::assertSame(['Redaktion' => 12], $context->groups());
    }

    #[Test]
    public function remembersAndReturnsWorkspaces(): void
    {
        $context = new SetupContext();

        self::assertSame(0, $context->workspace('Unknown'));

        $context->rememberWorkspace('Redaktion', 4);
        self::assertSame(4, $context->workspace('Redaktion'));
    }

    #[Test]
    public function recordsAndReturnsMessages(): void
    {
        $context = new SetupContext();

        self::assertSame([], $context->messages());

        $context->report('First message');
        $context->report('Second message');

        self::assertSame(['First message', 'Second message'], $context->messages());
    }
}
