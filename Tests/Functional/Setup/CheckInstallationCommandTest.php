<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Functional\Setup;

use GbWebDe\PublishPageButton\Command\CheckInstallationCommand;
use GbWebDe\PublishPageButton\Command\SetupCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CheckInstallationCommandTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web-de/publish-page-button',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function anEmptyInstallationFails(): void
    {
        [$status, $display] = $this->runCheck();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('keine Seiten', $display);
        self::assertStringContainsString('keinen Workspace', $display);
        self::assertStringContainsString('publishpage:setup fresh', $display);
    }

    #[Test]
    public function whatSetupBuildsPassesWithoutWarnings(): void
    {
        $this->runSetup(['scenario' => 'fresh', '--editor' => ['jana'], '--editor-password' => 'Password.1']);

        [$status, $display] = $this->runCheck();

        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('einsatzbereit', $display);
        self::assertStringContainsString('veroeffentlichen: jana', $display);
    }

    #[Test]
    public function groupPerWorkspacePassesWithoutWarnings(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->runSetup(['scenario' => 'group-per-workspace', '--group' => ['News,Sport'], '--editor' => ['jana:News', 'tom:Sport'], '--editor-password' => 'Password.1']);

        [$status, $display] = $this->runCheck();

        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('einsatzbereit', $display, 'Besitz ueber die eigene Gruppe muss zaehlen');
    }

    #[Test]
    public function aWorkspaceThatPublishesOnlyFromThePublishStageIsAnErrorThatFixRepairs(): void
    {
        $this->runSetup(['scenario' => 'fresh', '--editor' => ['jana'], '--editor-password' => 'Password.1']);
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->update('sys_workspace', ['publish_access' => 3], ['title' => 'Redaktion']);

        [$status, $display] = $this->runCheck();
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('nur aus der Publish-Stage', $display);

        [$status, $display] = $this->runCheck(['--fix' => true]);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertSame(2, (int)$this->getConnectionPool()->getConnectionForTable('sys_workspace')->select(['publish_access'], 'sys_workspace', ['title' => 'Redaktion'])->fetchOne(), 'die anderen Bits bleiben');
    }

    /**
     * Der grosse Baum vor und nach existing-groups: Olaf hat das Seitenmodul, besitzt aber keinen Workspace
     * und darf Live bearbeiten. Anna besitzt erst nach dem Setup etwas - ueber ihre Gruppe.
     */
    #[Test]
    public function editorsWhoCannotPublishOrEditLiveAreNamed(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');
        $this->runSetup(['scenario' => 'existing-groups', '--attach-to' => ['Bildredaktion']]);

        [$status, $display] = $this->runCheck();
        self::assertSame(Command::SUCCESS, $status, 'Warnungen, keine Fehler');
        self::assertStringContainsString('besitzen aber keinen Workspace', $display);
        self::assertStringContainsString('anna, olaf', $display);
        self::assertStringContainsString('Live bearbeiten. Was sie dort aendern, ist sofort online, am Knopf vorbei: olaf', $display);
        self::assertStringContainsString('Gruppe "Online-Desk" darf Live bearbeiten. Ihre Redakteure landen in Live', $display);

        $this->runSetup(['scenario' => 'existing-groups', '--attach-to-editors' => true]);
        [, $display] = $this->runCheck(['--fix-live-editing' => true]);

        self::assertStringContainsString('Live bearbeiten abgeschaltet fuer: olaf, Gruppe "Online-Desk".', $display);
        self::assertStringNotContainsString('darf Live bearbeiten', $display);
        self::assertStringNotContainsString('besitzen aber keinen Workspace', $display);
        self::assertStringContainsString('veroeffentlichen: anna, olaf', $display);
    }

    #[Test]
    public function ownershipCountsThroughSubgroups(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');
        // Gruppe 10 schliesst 11 ein; besitzt 11 den Workspace, besitzt ihn jeder in 10.
        $this->runSetup(['scenario' => 'existing-groups', '--attach-to' => ['Redaktion Basis', 'Online-Desk']]);

        [, $display] = $this->runCheck();

        self::assertStringNotContainsString('besitzen aber keinen Workspace', $display);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: int, 1: string}
     */
    private function runCheck(array $options = []): array
    {
        $tester = new CommandTester($this->get(CheckInstallationCommand::class));
        $status = $tester->execute($options, ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runSetup(array $arguments): void
    {
        $tester = new CommandTester($this->get(SetupCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute($arguments, ['interactive' => false]), $tester->getDisplay());
    }
}
