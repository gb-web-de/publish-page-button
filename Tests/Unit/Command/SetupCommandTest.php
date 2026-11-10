<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Command;

use GbWebDe\PublishPageButton\Command\SetupCommand;
use GbWebDe\PublishPageButton\Setup\InvalidSetupOptions;
use GbWebDe\PublishPageButton\Setup\SetupContext;
use GbWebDe\PublishPageButton\Setup\SetupOptions;
use GbWebDe\PublishPageButton\Setup\SetupPlanner;
use GbWebDe\PublishPageButton\Setup\SetupScenario;
use GbWebDe\PublishPageButton\Setup\SetupStep;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SetupCommandTest extends UnitTestCase
{
    private SetupPlanner $planner;
    private SetupCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->resetSingletonInstances = true;
        parent::setUp();
        $GLOBALS['BE_USER'] = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
        $this->planner = $this->createMock(SetupPlanner::class);
        $this->command = new SetupCommand($this->planner);
        $this->tester = new CommandTester($this->command);
    }

    #[Test]
    public function nonInteractiveWithoutScenarioReturnsInvalid(): void
    {
        $status = $this->tester->execute([], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Bitte ein Szenario nennen', $this->tester->getDisplay());
    }

    #[Test]
    public function unknownScenarioReturnsInvalid(): void
    {
        $status = $this->tester->execute(['scenario' => 'unknown-scenario']);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Unbekanntes Szenario "unknown-scenario"', $this->tester->getDisplay());
    }

    #[Test]
    public function invalidOptionsFromPlannerReturnsInvalid(): void
    {
        $this->planner->method('plan')->willThrowException(new InvalidSetupOptions('Planner validation failure'));

        $status = $this->tester->execute(['scenario' => 'single-group']);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Planner validation failure', $this->tester->getDisplay());
    }

    #[Test]
    public function dryRunShowsPlanAndExitsWithoutRunningSteps(): void
    {
        $executed = false;
        $step = new SetupStep('Test-Schritt', function () use (&$executed): void {
            $executed = true;
        }, true);

        $this->planner->method('plan')->willReturn([$step]);

        $status = $this->tester->execute([
            'scenario' => 'single-group',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertFalse($executed);
        self::assertStringContainsString('Probelauf - nichts wurde geaendert', $this->tester->getDisplay());
    }

    #[Test]
    public function exitsEarlyWhenAllStepsAreUnchanged(): void
    {
        $step = SetupStep::unchanged('Nichts zu tun');
        $this->planner->method('plan')->willReturn([$step]);

        $status = $this->tester->execute(['scenario' => 'single-group']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Alles ist schon eingerichtet.', $this->tester->getDisplay());
    }

    #[Test]
    public function interactiveConfirmationCancelledExitsCleanly(): void
    {
        $executed = false;
        $step = new SetupStep('Schritt', function () use (&$executed): void {
            $executed = true;
        }, true);

        $this->planner->method('plan')->willReturn([$step]);

        // Simulating "no" to "Plan ausfuehren?"
        $this->tester->setInputs(['no']);
        $status = $this->tester->execute(['scenario' => 'single-group'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertFalse($executed);
        self::assertStringContainsString('Nichts wurde geaendert.', $this->tester->getDisplay());
    }

    #[Test]
    public function executesStepsAndDisplaysOutputMessages(): void
    {
        $executed = false;
        $step = new SetupStep('Gruppe anlegen', function (SetupContext $context) use (&$executed): void {
            $executed = true;
            $context->rememberGroup('Redaktion', 10);
            $context->report('Gruppe uid 10 angelegt.');
        }, true);

        $this->planner->method('plan')->willReturn([$step]);

        $status = $this->tester->execute(['scenario' => 'single-group'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue($executed);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Gruppe uid 10 angelegt.', $display);
        self::assertStringContainsString('Fertig.', $display);
        self::assertStringContainsString('Ergebnis pruefen: publishpage:check', $display);
    }

    #[Test]
    public function interactiveWizardForExistingGroups(): void
    {
        $this->planner->method('plan')->willReturnCallback(function (SetupOptions $options): array {
            self::assertSame(SetupScenario::ExistingGroups, $options->scenario);
            self::assertFalse($options->attachToEditors);
            self::assertSame(['Redakteure'], $options->attachTo);
            self::assertSame('Mein Workspace', $options->workspace);

            return [SetupStep::unchanged('OK')];
        });

        // 1: Choose ExistingGroups (index 3 in choices)
        // 2: attach-to-editors? no
        // 3: which groups? Redakteure
        // 4: workspace? Mein Workspace
        $this->tester->setInputs(['existing-groups', 'no', 'Redakteure', 'Mein Workspace']);
        $status = $this->tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function interactiveWizardForGroupPerWorkspace(): void
    {
        $this->planner->method('plan')->willReturnCallback(function (SetupOptions $options): array {
            self::assertSame(SetupScenario::GroupPerWorkspace, $options->scenario);
            self::assertSame(['News', 'Sport'], $options->groups);
            self::assertSame(['jana' => 'News'], $options->editors);
            self::assertSame('GeheimesPasswort.1', $options->editorPassword);

            return [SetupStep::unchanged('OK')];
        });

        // 1: scenario: group-per-workspace
        // 2: groups: News,Sport
        // 3: editors: jana
        // 4: password: GeheimesPasswort.1
        $this->tester->setInputs(['group-per-workspace', 'News,Sport', 'jana', 'GeheimesPasswort.1']);
        $status = $this->tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function interactiveWizardForSingleGroup(): void
    {
        $this->planner->method('plan')->willReturnCallback(function (SetupOptions $options): array {
            self::assertSame(SetupScenario::SingleGroup, $options->scenario);
            self::assertSame(['Online-Desk'], $options->groups);
            self::assertSame('Online-Workspace', $options->workspace);
            self::assertSame(['jana' => 'Online-Desk'], $options->editors);
            self::assertSame('Secret.1', $options->editorPassword);

            return [SetupStep::unchanged('OK')];
        });

        // 1: scenario (default or single-group)
        // 2: group name: Online-Desk
        // 3: workspace name: Online-Workspace
        // 4: editors: jana
        // 5: password: Secret.1
        $this->tester->setInputs(['single-group', 'Online-Desk', 'Online-Workspace', 'jana', 'Secret.1']);
        $status = $this->tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function editorPasswordFromEnvironmentVariable(): void
    {
        putenv(SetupCommand::PASSWORD_VARIABLE . '=EnvSecret.1');

        $this->planner->method('plan')->willReturnCallback(function (SetupOptions $options): array {
            self::assertSame('EnvSecret.1', $options->editorPassword);

            return [SetupStep::unchanged('OK')];
        });

        try {
            $status = $this->tester->execute(['scenario' => 'single-group']);
            self::assertSame(Command::SUCCESS, $status);
        } finally {
            putenv(SetupCommand::PASSWORD_VARIABLE);
        }
    }

    #[Test]
    public function editorPasswordFromOptionTakesPrecedence(): void
    {
        putenv(SetupCommand::PASSWORD_VARIABLE . '=EnvSecret.1');

        $this->planner->method('plan')->willReturnCallback(function (SetupOptions $options): array {
            self::assertSame('OptionSecret.1', $options->editorPassword);

            return [SetupStep::unchanged('OK')];
        });

        try {
            $status = $this->tester->execute([
                'scenario' => 'single-group',
                '--editor-password' => 'OptionSecret.1',
            ]);
            self::assertSame(Command::SUCCESS, $status);
        } finally {
            putenv(SetupCommand::PASSWORD_VARIABLE);
        }
    }
}
