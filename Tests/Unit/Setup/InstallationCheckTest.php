<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\Finding;
use GbWebDe\PublishPageButton\Setup\InstallationCheck;
use GbWebDe\PublishPageButton\Setup\InstallationRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class InstallationCheckTest extends UnitTestCase
{
    #[Test]
    public function recognizesWorkspacesThatPublishOnlyFromPublishStage(): void
    {
        self::assertTrue(InstallationCheck::publishesOnlyFromPublishStage([
            'publish_access' => InstallationRepository::PUBLISH_ONLY_FROM_PUBLISH_STAGE,
        ]));
        self::assertFalse(InstallationCheck::publishesOnlyFromPublishStage([
            'publish_access' => 0,
        ]));
        self::assertFalse(InstallationCheck::publishesOnlyFromPublishStage([]));
    }

    #[Test]
    public function reportsErrorWhenNoPagesAndNoWorkspace(): void
    {
        $repo = $this->createMock(InstallationRepository::class);
        $repo->method('countPages')->willReturn(0);
        $repo->method('findAllWorkspaces')->willReturn([]);
        $repo->method('findAllGroups')->willReturn([]);
        $repo->method('findEditors')->willReturn([]);

        $check = new InstallationCheck($repo);
        $findings = $check->run();

        self::assertCount(3, $findings);
        self::assertSame('no-pages', $findings[0]->code);
        self::assertSame(Finding::ERROR, $findings[0]->severity);

        self::assertSame('no-workspace', $findings[1]->code);
        self::assertSame(Finding::ERROR, $findings[1]->severity);

        self::assertSame('no-editors', $findings[2]->code);
        self::assertSame(Finding::WARNING, $findings[2]->severity);
    }

    #[Test]
    public function checksWorkspacesWithAllVariants(): void
    {
        $repo = $this->createMock(InstallationRepository::class);
        $repo->method('countPages')->willReturn(5);
        $repo->method('findAllWorkspaces')->willReturn([
            ['uid' => 1, 'title' => 'StageOnly WS', 'publish_access' => 1, 'adminusers' => 'be_groups_1'],
            ['uid' => 2, 'title' => 'NoOwner WS', 'publish_access' => 0, 'adminusers' => ''],
            ['uid' => 3, 'title' => 'Ok WS', 'publish_access' => 0, 'adminusers' => 'be_groups_1'],
        ]);
        $repo->method('findAllGroups')->willReturn([
            ['uid' => 1, 'title' => 'Redaktion', 'groupMods' => 'web_layout', 'subgroup' => '', 'workspace_perms' => 0],
        ]);
        $repo->method('findEditors')->willReturn([]);

        $check = new InstallationCheck($repo);
        $findings = $check->run();

        $codes = array_map(static fn (Finding $f): string => $f->code, $findings);
        self::assertContains('pages', $codes);
        self::assertContains('publish-stage-only', $codes);
        self::assertContains('no-owner', $codes);
        self::assertContains('workspace', $codes);
    }

    #[Test]
    public function checksEditorsWithOwnershipSubgroupsLiveEditingAndTruncation(): void
    {
        $repo = $this->createMock(InstallationRepository::class);
        $repo->method('countPages')->willReturn(1);
        $repo->method('findAllWorkspaces')->willReturn([
            ['uid' => 1, 'title' => 'WS', 'publish_access' => 0, 'adminusers' => 'be_groups_1,be_users_99'],
        ]);

        // Group 1 owns WS. Group 2 is subgroup of Group 3.
        $repo->method('findAllGroups')->willReturn([
            ['uid' => 1, 'title' => 'OwnerGroup', 'groupMods' => 'web_layout', 'subgroup' => '', 'workspace_perms' => 0],
            ['uid' => 2, 'title' => 'Subgroup', 'groupMods' => 'web_layout', 'subgroup' => '1', 'workspace_perms' => 0],
            ['uid' => 3, 'title' => 'LiveGroup', 'groupMods' => '', 'subgroup' => '2', 'workspace_perms' => InstallationRepository::EDIT_LIVE],
            ['uid' => 4, 'title' => 'NoOwnerGroup', 'groupMods' => 'web_layout', 'subgroup' => '', 'workspace_perms' => 0],
        ]);

        // More than 10 editors to trigger shortList truncation
        $editors = [];
        for ($i = 1; $i <= 12; $i++) {
            $editors[] = [
                'uid' => $i,
                'username' => 'editor' . $i,
                'usergroup' => '1',
                'workspace_perms' => 0,
            ];
        }
        // Direct user ownership (be_users_99)
        $editors[] = [
            'uid' => 99,
            'username' => 'user_direct',
            'usergroup' => '4',
            'workspace_perms' => 0,
        ];
        // Editor with subgroup ownership
        $editors[] = [
            'uid' => 100,
            'username' => 'user_subgroup',
            'usergroup' => '3',
            'workspace_perms' => 0,
        ];
        // Editor without ownership
        $editors[] = [
            'uid' => 101,
            'username' => 'user_no_owner',
            'usergroup' => '4',
            'workspace_perms' => 0,
        ];
        // Editor with live editing
        $editors[] = [
            'uid' => 102,
            'username' => 'user_live',
            'usergroup' => '1',
            'workspace_perms' => InstallationRepository::EDIT_LIVE,
        ];

        $repo->method('findEditors')->willReturn($editors);

        $check = new InstallationCheck($repo);
        $findings = $check->run();

        $codes = array_map(static fn (Finding $f): string => $f->code, $findings);
        self::assertContains('publishers', $codes);
        self::assertContains('cannot-publish', $codes);
        self::assertContains('users-edit-live', $codes);
        self::assertContains('group-edits-live', $codes);

        $publisherFinding = array_values(array_filter($findings, static fn (Finding $f): bool => $f->code === 'publishers'))[0];
        self::assertStringContainsString('...', $publisherFinding->message, 'More than 10 publishers should trigger truncation');
    }

    #[Test]
    public function handlesCyclicAndMissingSubgroupsSafely(): void
    {
        $repo = $this->createMock(InstallationRepository::class);
        $repo->method('countPages')->willReturn(1);
        $repo->method('findAllWorkspaces')->willReturn([
            ['uid' => 1, 'title' => 'WS', 'publish_access' => 0, 'adminusers' => 'be_groups_1'],
        ]);

        // Group 1 has subgroup 2; Group 2 has subgroup 1 (cycle) and subgroup 999 (missing)
        $repo->method('findAllGroups')->willReturn([
            ['uid' => 1, 'title' => 'G1', 'groupMods' => 'web_layout', 'subgroup' => '2', 'workspace_perms' => 0],
            ['uid' => 2, 'title' => 'G2', 'groupMods' => '', 'subgroup' => '1,999', 'workspace_perms' => 0],
            ['uid' => 3, 'title' => 'G3_NoPageModule', 'groupMods' => 'records', 'subgroup' => '', 'workspace_perms' => 0],
        ]);

        $repo->method('findEditors')->willReturn([
            ['uid' => 10, 'username' => 'cycle_user', 'usergroup' => '2', 'workspace_perms' => 0],
            ['uid' => 11, 'username' => 'no_page_user', 'usergroup' => '3', 'workspace_perms' => 0],
        ]);

        $check = new InstallationCheck($repo);
        $findings = $check->run();

        $codes = array_map(static fn (Finding $f): string => $f->code, $findings);
        self::assertContains('publishers', $codes);
    }

    #[Test]
    public function fixPublishAccessClearsStageBit(): void
    {
        $repo = $this->createMock(InstallationRepository::class);
        $repo->method('findAllWorkspaces')->willReturn([
            ['uid' => 1, 'title' => 'WS1', 'publish_access' => 1],
            ['uid' => 2, 'title' => 'WS2', 'publish_access' => 0],
        ]);

        $repo->expects(self::once())
            ->method('allowPublishingOutsidePublishStage')
            ->with(1, 1);

        $check = new InstallationCheck($repo);
        $changed = $check->fixPublishAccess();

        self::assertSame(['WS1'], $changed);
    }

    #[Test]
    public function disallowLiveEditingRemovesPermissionsFromUsersAndGroups(): void
    {
        $repo = $this->createMock(InstallationRepository::class);
        $repo->method('findAllGroups')->willReturn([
            ['uid' => 1, 'title' => 'Redaktion', 'groupMods' => 'web_layout', 'subgroup' => '', 'workspace_perms' => InstallationRepository::EDIT_LIVE],
        ]);
        $repo->method('findEditors')->willReturn([
            ['uid' => 10, 'username' => 'editor10', 'usergroup' => '1', 'workspace_perms' => InstallationRepository::EDIT_LIVE],
        ]);

        $repo->expects(self::once())
            ->method('disallowLiveEditing')
            ->with(10, InstallationRepository::EDIT_LIVE);
        $repo->expects(self::once())
            ->method('disallowLiveEditingForGroup')
            ->with(1, InstallationRepository::EDIT_LIVE);

        $check = new InstallationCheck($repo);
        $changed = $check->disallowLiveEditingForEditors();

        self::assertSame(['editor10', 'Gruppe "Redaktion"'], $changed);
    }
}
