<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\InstallationRepository;
use GbWebDe\PublishPageButton\Setup\InvalidSetupOptions;
use GbWebDe\PublishPageButton\Setup\SetupContext;
use GbWebDe\PublishPageButton\Setup\SetupOptions;
use GbWebDe\PublishPageButton\Setup\SetupPlanner;
use GbWebDe\PublishPageButton\Setup\SetupScenario;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SetupPlannerTest extends UnitTestCase
{
    private InstallationRepository $installation;
    private SiteWriter $siteWriter;
    private SetupPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installation = $this->createMock(InstallationRepository::class);
        $this->siteWriter = $this->createMock(SiteWriter::class);
        $this->planner = new SetupPlanner($this->installation, $this->siteWriter);
    }

    #[Test]
    public function existingGroupsThrowsWhenNamedGroupDoesNotExist(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::ExistingGroups, [
            'attach-to' => ['NonExistentGroup'],
        ]);

        $this->installation->method('findGroup')->with('NonExistentGroup')->willReturn(null);

        $this->expectException(InvalidSetupOptions::class);
        $this->expectExceptionMessage('Es gibt keine Backend-Gruppe "NonExistentGroup"');

        $this->planner->plan($options);
    }

    #[Test]
    public function existingGroupsThrowsWhenAttachToEditorsFindsNoGroups(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::ExistingGroups, [
            'attach-to-editors' => true,
        ]);

        $this->installation->method('findGroupsWithModule')->with('web_layout')->willReturn([]);

        $this->expectException(InvalidSetupOptions::class);
        $this->expectExceptionMessage('Keine Gruppe hat das Seitenmodul (web_layout)');

        $this->planner->plan($options);
    }

    #[Test]
    public function existingGroupsPlansUnchangedGroupsAndWorkspace(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::ExistingGroups, [
            'attach-to-editors' => true,
            'attach-to' => ['SpecialGroup'],
            'workspace' => 'Redaktions-Workspace',
        ]);

        $this->installation->method('findGroupsWithModule')->with('web_layout')->willReturn([
            ['uid' => 1, 'title' => 'Redakteure'],
        ]);
        $this->installation->method('findGroup')->willReturnCallback(static function (string $ref): ?array {
            if ($ref === 'SpecialGroup') {
                return ['uid' => 2, 'title' => 'SpecialGroup'];
            }
            if ($ref === 'Redakteure') {
                return ['uid' => 1, 'title' => 'Redakteure'];
            }
            return null;
        });
        $this->installation->method('findWorkspaceByTitle')->with('Redaktions-Workspace')->willReturn([
            'uid' => 10,
            'title' => 'Redaktions-Workspace',
            'adminusers' => 'be_groups_1,be_groups_2',
            'publish_access' => 0,
        ]);

        $steps = $this->planner->plan($options);

        self::assertCount(3, $steps);
        self::assertStringContainsString('Gruppe "Redakteure" (uid 1) bleibt', $steps[0]->description);
        self::assertStringContainsString('Gruppe "SpecialGroup" (uid 2) bleibt', $steps[1]->description);
        self::assertStringContainsString('Workspace "Redaktions-Workspace" (uid 10) bleibt', $steps[2]->description);
        self::assertFalse($steps[2]->changesSomething, 'Already owned workspace requires no changes');

        $context = new SetupContext();
        $steps[2]->run($context);
        self::assertSame(10, $context->workspace('Redaktions-Workspace'));
    }

    #[Test]
    public function freshPlansPageTreeWhenNoPagesExist(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::Fresh, [
            'site-base' => 'https://example.org/',
        ]);

        $this->installation->method('countPages')->willReturn(0);
        $this->installation->method('createRootPage')->with('Startseite')->willReturn(1);
        $this->siteWriter->expects(self::once())
            ->method('createNewBasicSite')
            ->with('main', 1, 'https://example.org/');

        $this->installation->method('findGroup')->willReturn(null);
        $this->installation->method('createEditorGroup')->willReturn(5);
        $this->installation->method('grantUnownedPages')->willReturn(1);
        $this->installation->method('countPagesOwnedByOtherGroups')->willReturn(2);
        $this->installation->method('findWorkspaceByTitle')->willReturn(null);
        $this->installation->method('createWorkspace')->willReturn(7);

        $steps = $this->planner->plan($options);

        self::assertGreaterThanOrEqual(3, count($steps));
        self::assertStringContainsString('Startseite "Startseite" und Site "main"', $steps[0]->description);

        $context = new SetupContext();
        $steps[0]->run($context); // creates root page & site
        self::assertContains('Startseite uid 1, Site "main".', $context->messages());

        $steps[1]->run($context); // creates group
        self::assertContains('Gruppe "Redaktion" ist uid 5 und besitzt 1 Seite(n).', $context->messages());
        self::assertStringContainsString('2 Seite(n) gehoeren schon einer anderen Gruppe', implode(' ', $context->messages()));

        $steps[2]->run($context); // creates workspace
        self::assertContains('Workspace "Redaktion" ist uid 7.', $context->messages());
    }

    #[Test]
    public function freshPageTreeThrowsWhenRootPageCannotBeCreated(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::Fresh, []);
        $this->installation->method('countPages')->willReturn(0);
        $this->installation->method('createRootPage')->willReturn(0); // failure

        $steps = $this->planner->plan($options);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Die Startseite liess sich nicht anlegen.');

        $steps[0]->run(new SetupContext());
    }

    #[Test]
    public function freshKeepsExistingPageTree(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::Fresh, []);
        $this->installation->method('countPages')->willReturn(15);
        $this->installation->method('findGroup')->willReturn(null);
        $this->installation->method('createEditorGroup')->willReturn(1);
        $this->installation->method('findWorkspaceByTitle')->willReturn(null);
        $this->installation->method('createWorkspace')->willReturn(1);

        $steps = $this->planner->plan($options);

        self::assertStringContainsString('Seitenbaum bleibt (15 Seiten).', $steps[0]->description);
        self::assertFalse($steps[0]->changesSomething);
    }

    #[Test]
    public function singleGroupWithExistingGroupNeedingPageModule(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, [
            'group' => 'Autoren',
        ]);

        $this->installation->method('findGroup')->with('Autoren')->willReturn([
            'uid' => 3,
            'title' => 'Autoren',
            'groupMods' => 'records', // lacks web_layout
        ]);
        $this->installation->method('findWorkspaceByTitle')->willReturn(null);
        $this->installation->method('createWorkspace')->willReturn(1);

        $this->installation->expects(self::once())
            ->method('addModulesToGroup')
            ->with(3, 'records', ['web_layout']);

        $steps = $this->planner->plan($options);

        self::assertStringContainsString('Bestehender Gruppe "Autoren" (uid 3) das Seitenmodul geben', $steps[0]->description);
        self::assertTrue($steps[0]->changesSomething);

        $context = new SetupContext();
        $steps[0]->run($context);
        self::assertSame(3, $context->group('Autoren'));
    }

    #[Test]
    public function singleGroupThrowsWhenEditorGroupCannotBeCreated(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, ['group' => 'FehlerGruppe']);
        $this->installation->method('findGroup')->willReturn(null);
        $this->installation->method('createEditorGroup')->willReturn(0); // failure
        $this->installation->method('findWorkspaceByTitle')->willReturn(null);
        $this->installation->method('createWorkspace')->willReturn(1);

        $steps = $this->planner->plan($options);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Die Gruppe "FehlerGruppe" liess sich nicht anlegen.');

        $steps[0]->run(new SetupContext());
    }

    #[Test]
    public function groupPerWorkspacePlansSharedGroupAndInheritingGroups(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::GroupPerWorkspace, [
            'group' => 'News,Sport',
        ]);

        $this->installation->method('findGroup')->willReturn(null);
        $this->installation->method('createEditorGroup')->willReturn(10); // shared group
        $this->installation->method('createInheritingGroup')->willReturn(11); // news / sport
        $this->installation->method('findWorkspaceByTitle')->willReturn(null);
        $this->installation->method('createWorkspace')->willReturn(20);

        $steps = $this->planner->plan($options);

        // 1. Shared Group step
        self::assertStringContainsString(SetupPlanner::SHARED_GROUP, $steps[0]->description);
        // 2. News step
        self::assertStringContainsString('Gruppe "News" anlegen, die Seiten und Rechte von "' . SetupPlanner::SHARED_GROUP . '" erbt', $steps[1]->description);
        // 3. Sport step
        self::assertStringContainsString('Gruppe "Sport" anlegen', $steps[2]->description);
        // 4. News WS step
        self::assertStringContainsString('Workspace "News"', $steps[3]->description);
        // 5. Sport WS step
        self::assertStringContainsString('Workspace "Sport"', $steps[4]->description);

        $context = new SetupContext();
        $steps[0]->run($context); // Shared group
        $steps[1]->run($context); // News inheriting group
        self::assertSame(10, $context->group(SetupPlanner::SHARED_GROUP));
        self::assertSame(11, $context->group('News'));
    }

    #[Test]
    public function inheritingGroupThrowsWhenCreationFailed(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::GroupPerWorkspace, ['group' => 'News']);
        $this->installation->method('findGroup')->willReturn(null);
        $this->installation->method('createEditorGroup')->willReturn(10);
        $this->installation->method('createInheritingGroup')->willReturn(0); // failure
        $this->installation->method('findWorkspaceByTitle')->willReturn(null);
        $this->installation->method('createWorkspace')->willReturn(1);

        $steps = $this->planner->plan($options);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Die Gruppe "News" liess sich nicht anlegen.');

        $context = new SetupContext();
        $steps[0]->run($context);
        $steps[1]->run($context);
    }

    #[Test]
    public function planWorkspaceThrowsWhenCreationFailed(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, ['group' => 'Redaktion']);
        $this->installation->method('findGroup')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'groupMods' => 'web_layout']);
        $this->installation->method('findWorkspaceByTitle')->willReturn(null);
        $this->installation->method('createWorkspace')->willReturn(0); // failure

        $steps = $this->planner->plan($options);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Der Workspace "Redaktion" liess sich nicht anlegen.');

        $steps[1]->run(new SetupContext());
    }

    #[Test]
    public function planWorkspaceWarnsWhenPublishOnlyFromPublishStage(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, ['group' => 'Redaktion']);
        $this->installation->method('findGroup')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'groupMods' => 'web_layout']);
        $this->installation->method('findWorkspaceByTitle')->with('Redaktion')->willReturn([
            'uid' => 5,
            'title' => 'Redaktion',
            'adminusers' => 'be_groups_1',
            'publish_access' => 1, // only from publish stage
        ]);

        $steps = $this->planner->plan($options);

        self::assertStringContainsString('Achtung: Er erlaubt Veroeffentlichen nur aus der Publish-Stage', $steps[1]->description);
    }

    #[Test]
    public function planEditorWithExistingUserAlreadyInGroup(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, [
            'group' => 'Redaktion',
            'editor' => ['jana'],
        ]);

        $this->installation->method('findGroup')->with('Redaktion')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'groupMods' => 'web_layout']);
        $this->installation->method('findWorkspaceByTitle')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'adminusers' => 'be_groups_1', 'publish_access' => 0]);
        $this->installation->method('findUser')->with('jana')->willReturn([
            'uid' => 20,
            'username' => 'jana',
            'usergroup' => '1,2',
        ]);

        $steps = $this->planner->plan($options);

        self::assertCount(3, $steps);
        self::assertStringContainsString('Benutzer "jana" bleibt, ist schon in "Redaktion".', $steps[2]->description);
        self::assertFalse($steps[2]->changesSomething);
    }

    #[Test]
    public function planEditorWithExistingUserNeedingGroupAddition(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, [
            'group' => 'Redaktion',
            'editor' => ['jana'],
        ]);

        $this->installation->method('findGroup')->with('Redaktion')->willReturn(['uid' => 5, 'title' => 'Redaktion', 'groupMods' => 'web_layout']);
        $this->installation->method('findWorkspaceByTitle')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'adminusers' => 'be_groups_5', 'publish_access' => 0]);
        $this->installation->method('findUser')->with('jana')->willReturn([
            'uid' => 20,
            'username' => 'jana',
            'usergroup' => '2', // lacks group 5
        ]);

        $this->installation->expects(self::once())
            ->method('addUserToGroup')
            ->with(20, '2', 5);

        $steps = $this->planner->plan($options);

        self::assertStringContainsString('Bestehenden Benutzer "jana" in die Gruppe "Redaktion" aufnehmen.', $steps[2]->description);
        self::assertTrue($steps[2]->changesSomething);

        $context = new SetupContext();
        $context->rememberGroup('Redaktion', 5);
        $steps[2]->run($context);
    }

    #[Test]
    public function planEditorThrowsWhenPasswordIsMissing(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, [
            'group' => 'Redaktion',
            'editor' => ['jana'],
            'editor-password' => null,
        ]);

        $this->installation->method('findGroup')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'groupMods' => 'web_layout']);
        $this->installation->method('findWorkspaceByTitle')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'adminusers' => '', 'publish_access' => 0]);
        $this->installation->method('findUser')->with('jana')->willReturn(null); // new user!

        $this->expectException(InvalidSetupOptions::class);
        $this->expectExceptionMessage('Fuer den neuen Redakteur "jana" fehlt ein Passwort');

        $this->planner->plan($options);
    }

    #[Test]
    public function planEditorCreatesNewUserAndThrowsOnFailure(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, [
            'group' => 'Redaktion',
            'editor' => ['jana'],
            'editor-password' => 'Password.1',
        ]);

        $this->installation->method('findGroup')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'groupMods' => 'web_layout']);
        $this->installation->method('findWorkspaceByTitle')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'adminusers' => '', 'publish_access' => 0]);
        $this->installation->method('findUser')->with('jana')->willReturn(null);
        $this->installation->method('createEditor')->willReturn(0); // failure

        $steps = $this->planner->plan($options);

        self::assertStringContainsString('Redakteur "jana" in "Redaktion" anlegen', $steps[2]->description);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Der Redakteur "jana" liess sich nicht anlegen.');

        $steps[2]->run(new SetupContext());
    }

    #[Test]
    public function planEditorSuccessfullyCreatesNewUser(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, [
            'group' => 'Redaktion',
            'editor' => ['jana'],
            'editor-password' => 'Password.1',
        ]);

        $this->installation->method('findGroup')->willReturn(['uid' => 1, 'title' => 'Redaktion', 'groupMods' => 'web_layout']);
        $this->installation->method('findWorkspaceByTitle')->willReturn(['uid' => 2, 'title' => 'Redaktion', 'adminusers' => '', 'publish_access' => 0]);
        $this->installation->method('findUser')->with('jana')->willReturn(null);
        $this->installation->method('createEditor')->with('jana', 'Password.1', 1, 2)->willReturn(99);

        $steps = $this->planner->plan($options);

        $context = new SetupContext();
        $context->rememberGroup('Redaktion', 1);
        $context->rememberWorkspace('Redaktion', 2);
        $steps[2]->run($context);

        self::assertContains('Redakteur "jana" ist uid 99.', $context->messages());
    }
}
