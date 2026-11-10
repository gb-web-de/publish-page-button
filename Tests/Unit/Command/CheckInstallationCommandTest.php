<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Command;

use GbWebDe\PublishPageButton\Command\CheckInstallationCommand;
use GbWebDe\PublishPageButton\Setup\Finding;
use GbWebDe\PublishPageButton\Setup\InstallationCheck;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CheckInstallationCommandTest extends UnitTestCase
{
    private InstallationCheck $check;
    private CheckInstallationCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->resetSingletonInstances = true;
        parent::setUp();
        $GLOBALS['BE_USER'] = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
        $this->check = $this->createMock(InstallationCheck::class);
        $this->command = new CheckInstallationCommand($this->check);
        $this->tester = new CommandTester($this->command);
    }

    #[Test]
    public function returnsSuccessWhenAllFindingsAreOk(): void
    {
        $this->check->method('run')->willReturn([
            new Finding(Finding::OK, 'pages', '10 Seiten vorhanden.'),
            new Finding(Finding::OK, 'workspace', 'Workspace eingerichtet.'),
        ]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Der Knopf ist einsatzbereit.', $this->tester->getDisplay());
    }

    #[Test]
    public function returnsSuccessWithWarningSummaryWhenWarningsExist(): void
    {
        $this->check->method('run')->willReturn([
            new Finding(Finding::OK, 'pages', '10 Seiten vorhanden.'),
            new Finding(Finding::WARNING, 'no-owner', 'Workspace ohne Besitzer.', 'Bitte zuweisen.'),
        ]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('1 Warnung(en), keine Fehler.', $this->tester->getDisplay());
        self::assertStringContainsString('→ Bitte zuweisen.', $this->tester->getDisplay());
    }

    #[Test]
    public function returnsFailureWhenErrorsExist(): void
    {
        $this->check->method('run')->willReturn([
            new Finding(Finding::ERROR, 'no-pages', 'Keine Seiten vorhanden.', 'Bitte anlegen.'),
            new Finding(Finding::WARNING, 'no-owner', 'Kein Besitzer.'),
        ]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('1 Fehler, 1 Warnung(en).', $this->tester->getDisplay());
    }

    #[Test]
    public function handlesFixAndFixLiveEditingOptions(): void
    {
        $this->check->method('fixPublishAccess')->willReturn(['Redaktion', 'Marketing']);
        $this->check->method('disallowLiveEditingForEditors')->willReturn(['jana', 'Gruppe "Redaktion"']);
        $this->check->method('run')->willReturn([
            new Finding(Finding::OK, 'ok', 'Alles behoben.'),
        ]);

        $status = $this->tester->execute([
            '--fix' => true,
            '--fix-live-editing' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('2 Workspace(s) erlauben jetzt Veroeffentlichen per Knopf: Redaktion, Marketing.', $display);
        self::assertStringContainsString('Live bearbeiten abgeschaltet fuer: jana, Gruppe "Redaktion"', $display);
    }

    #[Test]
    public function handlesEmptyFixReports(): void
    {
        $this->check->method('fixPublishAccess')->willReturn([]);
        $this->check->method('disallowLiveEditingForEditors')->willReturn([]);
        $this->check->method('run')->willReturn([
            new Finding(Finding::OK, 'ok', 'Alles gut.'),
        ]);

        $status = $this->tester->execute([
            '--fix' => true,
            '--fix-live-editing' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('0 Workspace(s) erlauben jetzt Veroeffentlichen per Knopf.', $display);
        self::assertStringContainsString('Kein Redakteur mit Seitenmodul durfte Live bearbeiten.', $display);
    }
}
