<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Functional\Setup;

use GbWebDe\PublishPageButton\Command\SetupCommand;
use GbWebDe\PublishPageButton\Controller\PublishPageController;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Jedes Szenario von `publishpage:setup`, echt gegen den DataHandler.
 *
 * Am wichtigsten ist, was das Setup NICHT tut: Es nimmt keiner Gruppe ein Modul, uebernimmt keine Seite
 * einer anderen Gruppe und streicht keinen Workspace-Besitzer. Die meisten Tests pruefen das neben dem,
 * was dazukam. Und am Ende muss ein so eingerichteter Redakteur per Knopf wirklich veroeffentlichen koennen.
 */
final class SetupCommandTest extends FunctionalTestCase
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
    public function freshBuildsEverythingOnAnEmptyDatabase(): void
    {
        [$status, $display] = $this->runSetup(['scenario' => 'fresh']);

        self::assertSame(Command::SUCCESS, $status, $display);

        $pages = $this->rows('pages');
        self::assertCount(1, $pages);
        self::assertSame(1, (int)$pages[0]['is_siteroot']);

        $group = $this->groupTitled('Redaktion');
        self::assertSame('web_layout,web_edit,records,media_management', $group['groupMods']);
        self::assertSame((string)$pages[0]['uid'], (string)$group['db_mountpoints']);
        self::assertSame(0, (int)$group['workspace_perms'], 'wer Live bearbeiten darf, braucht den Knopf nie');
        self::assertSame('1,3,4,199,254', $group['pagetypes_select'], 'ohne Seitentypen lehnt der DataHandler jede Seitenaenderung ab');
        self::assertStringContainsString('tt_content:CType:text', (string)$group['explicit_allowdeny'], 'ohne CType-Freigabe kein einziges Inhaltselement');
        self::assertSame((int)$group['uid'], (int)$pages[0]['perms_groupid']);

        $workspace = $this->workspaceTitled('Redaktion');
        self::assertSame('be_groups_' . $group['uid'], $workspace['adminusers'], 'ohne Live-Zugriff duerfen nur Besitzer veroeffentlichen');
        self::assertSame(0, (int)$workspace['publish_access'], 'nur aus der Publish-Stage hiesse: der Knopf lehnt ab');
        self::assertSame(0, (int)$workspace['live_edit']);
    }

    #[Test]
    public function freshKeepsAPageTreeThatIsAlreadyThere(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');

        [, $display] = $this->runSetup(['scenario' => 'fresh']);

        self::assertStringContainsString('Seitenbaum bleibt (2 Seiten)', $display);
        self::assertCount(2, $this->rows('pages'));
    }

    #[Test]
    public function singleGroupGivesAnExistingGroupThePageModuleAndNothingElse(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');
        $before = $this->groupTitled('Bildredaktion');

        $this->runSetup(['scenario' => 'single-group', '--group' => ['Bildredaktion']]);

        $after = $this->groupTitled('Bildredaktion');
        self::assertSame('media_management,web_layout', $after['groupMods']);
        unset($before['groupMods'], $after['groupMods'], $before['tstamp'], $after['tstamp']);
        self::assertSame($before, $after, 'das Setup hat an einer bestehenden Gruppe mehr als die Modulliste geaendert');
        self::assertSame('be_groups_12', $this->workspaceTitled('Bildredaktion')['adminusers']);
    }

    #[Test]
    public function aNewGroupNeverTakesOverPagesOfAnotherGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');

        [, $display] = $this->runSetup(['scenario' => 'single-group', '--group' => ['Neue Redaktion']]);

        $owners = array_column($this->rows('pages'), 'perms_groupid', 'uid');
        self::assertSame(10, (int)$owners[1]);
        self::assertSame(10, (int)$owners[2]);
        self::assertSame((int)$this->groupTitled('Neue Redaktion')['uid'], (int)$owners[3], 'nur die Seite ohne Gruppe wird uebergeben');
        self::assertStringContainsString('2 Seite(n) gehoeren schon einer anderen Gruppe', $display);
    }

    #[Test]
    public function groupPerWorkspaceKeepsTheGroupsApart(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');

        [$status, $display] = $this->runSetup(['scenario' => 'group-per-workspace', '--group' => ['News,Sport']]);

        self::assertSame(Command::SUCCESS, $status, $display);
        $news = $this->groupTitled('News');
        $sport = $this->groupTitled('Sport');
        $shared = $this->groupTitled('Redaktion (gemeinsame Rechte)');
        self::assertSame('be_groups_' . $news['uid'], $this->workspaceTitled('News')['adminusers']);
        self::assertSame('be_groups_' . $sport['uid'], $this->workspaceTitled('Sport')['adminusers']);

        // Eine Seite gehoert nur einer Gruppe, also erreichen beide die Seiten ueber die gemeinsame - und
        // keine schliesst die andere ein, sonst duerfte Sport auch im Workspace News veroeffentlichen.
        self::assertSame((string)$shared['uid'], (string)$news['subgroup']);
        self::assertSame((string)$shared['uid'], (string)$sport['subgroup']);
        self::assertSame('', (string)$news['groupMods'], 'die Module kommen aus der gemeinsamen Gruppe');
        self::assertSame(0, (int)$news['workspace_perms']);
        foreach ($this->rows('pages') as $page) {
            self::assertSame((int)$shared['uid'], (int)$page['perms_groupid']);
        }
        self::assertCount(2, $this->rows('sys_workspace'), 'die gemeinsame Gruppe bekommt keinen Workspace');
    }

    /**
     * Der grosse Gruppenbaum: Keine Gruppe aendert sich, und keine Seite. Die Gruppen mit Seitenmodul
     * werden Besitzer eines Workspaces.
     */
    #[Test]
    public function existingGroupsLeavesTheTreeAsItIs(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');
        $groupsBefore = $this->rows('be_groups');
        $pagesBefore = $this->rows('pages');

        [$status, $display] = $this->runSetup(['scenario' => 'existing-groups', '--attach-to-editors' => true]);

        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertSame($groupsBefore, $this->rows('be_groups'));
        self::assertSame($pagesBefore, $this->rows('pages'));
        $workspace = $this->workspaceTitled('Redaktion');
        self::assertSame('be_groups_10,be_groups_13', $workspace['adminusers'], 'genau die beiden Gruppen mit Seitenmodul');
    }

    #[Test]
    public function existingGroupsTakesGroupsByTitleOrUid(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');

        $this->runSetup(['scenario' => 'existing-groups', '--attach-to' => ['Redaktion', '12'], '--workspace' => 'Online']);

        self::assertSame('be_groups_10,be_groups_12', $this->workspaceTitled('Online')['adminusers']);
    }

    #[Test]
    public function existingGroupsStopsOnAnUnknownGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');

        [$status, $display] = $this->runSetup(['scenario' => 'existing-groups', '--attach-to' => ['Gibtsnicht']]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Gibtsnicht', $display);
        self::assertSame([], $this->rows('sys_workspace'));
    }

    #[Test]
    public function anEditorCreatedBySetupWorksInTheWorkspaceFromTheStart(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');

        [$status, $display] = $this->runSetup([
            'scenario' => 'group-per-workspace',
            '--group' => ['News,Sport'],
            '--editor' => ['jana:Sport'],
            '--editor-password' => 'Password.1',
        ]);

        self::assertSame(Command::SUCCESS, $status, $display);
        $jana = $this->userNamed('jana');
        self::assertSame((string)$this->groupTitled('Sport')['uid'], (string)$jana['usergroup']);
        self::assertSame((int)$this->workspaceTitled('Sport')['uid'], (int)$jana['workspace_id']);
        self::assertSame(0, (int)$jana['workspace_perms'], 'wer Live bearbeitet, braucht den Knopf nie');
        self::assertSame(0, (int)$jana['admin']);
        self::assertSame(0, (int)$jana['disable'], 'ein deaktivierter Redakteur sieht nur "Login fehlgeschlagen"');
        self::assertNotSame('Password.1', $jana['password'], 'das Passwort muss gehasht gespeichert sein');
    }

    /**
     * Der Beweis, dass das Rechtemodell stimmt: Die Redakteurin bearbeitet im Workspace, und der Knopf
     * bringt die Aenderung live.
     */
    #[Test]
    public function anEditorSetUpThisWayCanPublishWithTheButton(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->runSetup(['scenario' => 'single-group', '--editor' => ['jana'], '--editor-password' => 'Password.1']);
        $workspaceUid = (int)$this->workspaceTitled('Redaktion')['uid'];

        $jana = $this->setUpBackendUser((int)$this->userNamed('jana')['uid']);
        $jana->setWorkspace($workspaceUid);
        self::assertSame($workspaceUid, $jana->workspace, 'die Redakteurin darf nicht in ihren Workspace');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($jana);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'pages' => [2 => ['title' => 'Ueber uns (neu)']],
            'tt_content' => ['NEW1' => ['pid' => 2, 'sys_language_uid' => 0, 'CType' => 'text', 'header' => 'Neu im Workspace']],
        ], [], $jana);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        self::assertSame('Ueber uns', $this->liveTitle(2), 'die Aenderung darf erst im Workspace stehen');

        $result = $this->get(PublishPageController::class)->publish($jana, 2);

        self::assertSame(2, $result, is_array($result) ? implode("\n", $result) : '');
        self::assertSame('Ueber uns (neu)', $this->liveTitle(2));
        $live = array_filter($this->rows('tt_content'), static fn (array $row): bool => (int)$row['t3ver_wsid'] === 0 && $row['header'] === 'Neu im Workspace');
        self::assertCount(1, $live, 'das neue Inhaltselement ist nicht live');
    }

    #[Test]
    public function anExistingUserOnlyJoinsTheGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $before = $this->userNamed('editor');

        $this->runSetup(['scenario' => 'single-group', '--editor' => ['editor']]);

        $after = $this->userNamed('editor');
        self::assertSame((string)$this->groupTitled('Redaktion')['uid'], (string)$after['usergroup']);
        self::assertSame($before['password'], $after['password']);
        self::assertSame($before['workspace_perms'], $after['workspace_perms']);
    }

    #[Test]
    public function aNewEditorWithoutPasswordStopsBeforeAnythingIsWritten(): void
    {
        [$status, $display] = $this->runSetup(['scenario' => 'fresh', '--editor' => ['jana']]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('PUBLISHPAGE_EDITOR_PASSWORD', $display);
        self::assertSame([], $this->rows('be_groups'));
        self::assertSame([], $this->rows('pages'));
    }

    #[Test]
    public function theEnvironmentVariableIsEnoughForAPassword(): void
    {
        putenv('PUBLISHPAGE_EDITOR_PASSWORD=Password.1');
        try {
            [$status, $display] = $this->runSetup(['scenario' => 'fresh', '--editor' => ['jana']]);
        } finally {
            putenv('PUBLISHPAGE_EDITOR_PASSWORD');
        }

        self::assertSame(Command::SUCCESS, $status, $display);
        $this->userNamed('jana');
    }

    #[Test]
    public function anExistingWorkspaceKeepsItsOwnersAndSaysWhenTheButtonWouldRefuse(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'pid' => 0,
            'title' => 'Redaktion',
            'adminusers' => 'be_users_2',
            'publish_access' => 1,
        ]);

        [, $display] = $this->runSetup(['scenario' => 'single-group']);

        self::assertSame('be_users_2,be_groups_' . $this->groupTitled('Redaktion')['uid'], $this->workspaceTitled('Redaktion')['adminusers']);
        self::assertStringContainsString('publishpage:check --fix', $display);
        self::assertSame(1, (int)$this->workspaceTitled('Redaktion')['publish_access'], 'das Setup schaltet das nicht still um');
    }

    #[Test]
    public function runningItAgainChangesNothing(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $arguments = ['scenario' => 'single-group', '--group' => ['Redaktion'], '--editor' => ['jana'], '--editor-password' => 'Password.1'];
        $this->runSetup($arguments);
        $before = [$this->rows('be_groups'), $this->rows('sys_workspace'), $this->rows('pages'), $this->rows('be_users')];

        [, $display] = $this->runSetup($arguments);

        self::assertSame($before, [$this->rows('be_groups'), $this->rows('sys_workspace'), $this->rows('pages'), $this->rows('be_users')]);
        self::assertStringContainsString('Gruppe "Redaktion"', $display);
        self::assertStringContainsString('Workspace "Redaktion" (uid', $display);
        self::assertStringContainsString('Alles ist schon eingerichtet', $display);
    }

    #[Test]
    public function aDryRunChangesNothing(): void
    {
        [$status, $display] = $this->runSetup(['scenario' => 'fresh', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Redaktionsgruppe "Redaktion" anlegen', $display);
        self::assertSame([], $this->rows('pages'));
        self::assertSame([], $this->rows('be_groups'));
        self::assertSame([], $this->rows('sys_workspace'));
    }

    #[Test]
    public function withoutAScenarioAndWithoutATerminalItSaysWhichOnesExist(): void
    {
        [$status, $display] = $this->runSetup([]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('existing-groups', $display);
    }

    #[Test]
    public function theWizardAsksAndThenDoesTheSameAsTheOptions(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $tester = new CommandTester($this->get(SetupCommand::class));
        $tester->setInputs([
            'single-group',      // Szenario
            'Online-Redaktion',  // Gruppe
            'Entwurf',           // Workspace
            '',                  // Redakteure
            'yes',               // ausfuehren
        ]);

        $status = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertSame('be_groups_' . $this->groupTitled('Online-Redaktion')['uid'], $this->workspaceTitled('Entwurf')['adminusers']);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: string}
     */
    private function runSetup(array $arguments): array
    {
        $tester = new CommandTester($this->get(SetupCommand::class));
        $status = $tester->execute($arguments, ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }

    private function liveTitle(int $uid): string
    {
        foreach ($this->rows('pages') as $page) {
            if ((int)$page['uid'] === $uid) {
                return (string)$page['title'];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function groupTitled(string $title): array
    {
        return $this->onlyRow('be_groups', 'title', $title);
    }

    /**
     * @return array<string, mixed>
     */
    private function userNamed(string $username): array
    {
        return $this->onlyRow('be_users', 'username', $username);
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceTitled(string $title): array
    {
        return $this->onlyRow('sys_workspace', 'title', $title);
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRow(string $table, string $field, string $value): array
    {
        $rows = array_values(array_filter($this->rows($table), static fn (array $row): bool => $row[$field] === $value));
        self::assertCount(1, $rows, sprintf('erwartet genau einen Datensatz in %s mit %s "%s"', $table, $field, $value));

        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from($table)->orderBy('uid')->executeQuery()->fetchAllAssociative();
    }
}
