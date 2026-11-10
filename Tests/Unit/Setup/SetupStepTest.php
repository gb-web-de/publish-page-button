<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\SetupContext;
use GbWebDe\PublishPageButton\Setup\SetupStep;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SetupStepTest extends UnitTestCase
{
    #[Test]
    public function createsStepAndRunsAction(): void
    {
        $executed = false;
        $context = new SetupContext();

        $step = new SetupStep('Test Step', function (SetupContext $ctx) use (&$executed, $context): void {
            self::assertSame($context, $ctx);
            $executed = true;
        }, true);

        self::assertSame('Test Step', $step->description);
        self::assertTrue($step->changesSomething);

        $step->run($context);
        self::assertTrue($executed);
    }

    #[Test]
    public function createsUnchangedStep(): void
    {
        $step = SetupStep::unchanged('Nothing changed');

        self::assertSame('Nothing changed', $step->description);
        self::assertFalse($step->changesSomething);

        $context = new SetupContext();
        // Should execute without errors or side effects
        $step->run($context);
        self::assertSame([], $context->messages());
    }
}
