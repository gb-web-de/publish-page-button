<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\InvalidSetupOptions;
use GbWebDe\PublishPageButton\Setup\SetupOptions;
use GbWebDe\PublishPageButton\Setup\SetupPlanner;
use GbWebDe\PublishPageButton\Setup\SetupScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SetupOptionsTest extends UnitTestCase
{
    #[Test]
    public function freshNeedsNoOptionsAtAll(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::Fresh, []);

        self::assertSame([SetupOptions::DEFAULT_GROUP], $options->groups);
        self::assertSame(['Redaktion' => ['Redaktion']], $options->workspacesWithOwnerGroups());
        self::assertSame('/', $options->siteBase);
        self::assertFalse($options->dryRun);
        self::assertSame([], $options->editors);
    }

    #[Test]
    public function singleGroupTakesAnyGroupName(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, ['group' => 'Online-Redaktion']);

        self::assertSame(['Online-Redaktion' => ['Online-Redaktion']], $options->workspacesWithOwnerGroups(), 'der Workspace heisst wie die Gruppe');
    }

    #[Test]
    public function singleGroupCanWorkInAWorkspaceOfAnotherName(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, ['group' => 'Redaktion', 'workspace' => 'Entwurf']);

        self::assertSame(['Entwurf' => ['Redaktion']], $options->workspacesWithOwnerGroups());
        self::assertSame('Entwurf', $options->workspaceOfGroup('Redaktion'));
    }

    #[Test]
    public function groupPerWorkspaceGivesEveryGroupItsOwnWorkspace(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::GroupPerWorkspace, ['group' => ['News,Sport', 'Kultur']]);

        self::assertSame(['News' => ['News'], 'Sport' => ['Sport'], 'Kultur' => ['Kultur']], $options->workspacesWithOwnerGroups());
        self::assertSame('Sport', $options->workspaceOfGroup('Sport'));
    }

    #[Test]
    public function existingGroupsCreatesNoGroupAndUsesTheDefaultWorkspace(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::ExistingGroups, ['attach-to-editors' => true]);

        self::assertSame([], $options->groups);
        self::assertSame(SetupOptions::DEFAULT_WORKSPACE, $options->workspace);
        self::assertTrue($options->attachToEditors);
    }

    #[Test]
    public function editorsGoIntoTheFirstGroupUnlessOneIsNamed(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::GroupPerWorkspace, ['group' => 'News,Sport', 'editor' => 'jana, tom:Sport']);

        self::assertSame(['jana' => 'News', 'tom' => 'Sport'], $options->editors);
    }

    #[Test]
    public function anEmptyPasswordCountsAsNone(): void
    {
        self::assertNull(SetupOptions::fromArray(SetupScenario::Fresh, ['editor-password' => ''])->editorPassword);
    }

    public static function invalidOptions(): \Generator
    {
        yield 'fresh mit zwei Gruppen' => [SetupScenario::Fresh, ['group' => 'News,Sport'], 'group-per-workspace'];
        yield 'single-group mit zwei Gruppen' => [SetupScenario::SingleGroup, ['group' => ['News', 'Sport']], 'genau eine Gruppe'];
        yield 'group-per-workspace ohne Gruppen' => [SetupScenario::GroupPerWorkspace, [], '--group='];
        yield 'group-per-workspace mit --workspace' => [SetupScenario::GroupPerWorkspace, ['group' => 'News', 'workspace' => 'X'], '--workspace'];
        yield 'group-per-workspace mit dem Namen der gemeinsamen Gruppe' => [SetupScenario::GroupPerWorkspace, ['group' => SetupPlanner::SHARED_GROUP], 'anderen Namen'];
        yield 'existing-groups ohne Ziel' => [SetupScenario::ExistingGroups, [], '--attach-to'];
        yield 'existing-groups mit --group' => [SetupScenario::ExistingGroups, ['group' => 'X', 'attach-to-editors' => true], 'keine Gruppen an'];
        yield 'existing-groups mit --editor' => [SetupScenario::ExistingGroups, ['attach-to-editors' => true, 'editor' => 'jana'], '--editor'];
        yield '--attach-to ausserhalb von existing-groups' => [SetupScenario::SingleGroup, ['attach-to' => 'Redaktion'], 'existing-groups'];
        yield '--editor in fremde Gruppe' => [SetupScenario::SingleGroup, ['editor' => 'jana:Sport'], 'jana:Sport'];
        yield 'zu langer Gruppenname' => [SetupScenario::SingleGroup, ['group' => str_repeat('x', 51)], '50 Zeichen'];
        yield 'leerer Benutzername' => [SetupScenario::SingleGroup, ['editor' => ':Redaktion'], '--editor='];
    }

    #[Test]
    public function workspaceOfGroupReturnsNullForUnknownGroup(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, ['group' => 'Redaktion']);

        self::assertNull($options->workspaceOfGroup('Unbekannt'));
    }

    /**
     * @param array<string, mixed> $values
     */
    #[Test]
    #[DataProvider('invalidOptions')]
    public function contradictoryOptionsAreRejectedWithAHint(SetupScenario $scenario, array $values, string $hint): void
    {
        $this->expectException(InvalidSetupOptions::class);
        $this->expectExceptionMessage($hint);

        SetupOptions::fromArray($scenario, $values);
    }

    #[Test]
    public function theSharedGroupNameFitsIntoAGroupTitle(): void
    {
        self::assertLessThanOrEqual(50, mb_strlen(SetupPlanner::SHARED_GROUP));
    }
}
