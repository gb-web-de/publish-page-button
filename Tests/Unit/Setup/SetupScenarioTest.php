<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\SetupScenario;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SetupScenarioTest extends UnitTestCase
{
    /**
     * Diese Namen tippen Menschen und stehen in Deploy-Skripten und der Anleitung - umbenennen bricht beides.
     */
    #[Test]
    public function scenarioNamesAreStable(): void
    {
        self::assertSame(['fresh', 'single-group', 'group-per-workspace', 'existing-groups'], SetupScenario::values());
    }

    #[Test]
    public function everyScenarioHasALabelForTheWizard(): void
    {
        foreach (SetupScenario::cases() as $scenario) {
            self::assertNotSame('', $scenario->label());
        }
    }
}
