<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use Doctrine\DBAL\Result;
use GbWebDe\PublishPageButton\Setup\InstallationRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class InstallationRepositoryTest extends UnitTestCase
{
    private ConnectionPool $connectionPool;
    private Connection $connection;
    private QueryBuilder $queryBuilder;
    private ExpressionBuilder $expressionBuilder;
    private Result $result;
    private InstallationRepository $subject;

    protected function setUp(): void
    {
        $this->resetSingletonInstances = true;
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->connection = $this->createMock(Connection::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->expressionBuilder = new ExpressionBuilder($this->connection);
        $this->result = $this->createMock(Result::class);

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $this->queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $this->queryBuilder->method('expr')->willReturn($this->expressionBuilder);
        $this->queryBuilder->method('createNamedParameter')->willReturnCallback(static fn ($val): string => (string)$val);
        $this->queryBuilder->method('count')->willReturnSelf();
        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('setMaxResults')->willReturnSelf();
        $this->queryBuilder->method('update')->willReturnSelf();
        $this->queryBuilder->method('set')->willReturnSelf();
        $this->queryBuilder->method('executeQuery')->willReturn($this->result);

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($this->queryBuilder);
        $this->connectionPool->method('getConnectionForTable')->willReturn($this->connection);

        $this->subject = new InstallationRepository($this->connectionPool);
    }

    #[Test]
    public function countPagesReturnsInteger(): void
    {
        $this->result->method('fetchOne')->willReturn('42');

        self::assertSame(42, $this->subject->countPages());
    }

    #[Test]
    public function findRootPageUidsReturnsListOfInts(): void
    {
        $this->result->method('fetchFirstColumn')->willReturn(['1', '2', '5']);

        self::assertSame([1, 2, 5], $this->subject->findRootPageUids());
    }

    #[Test]
    public function findGroupByTitleOrUid(): void
    {
        // By string title
        $this->result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['uid' => 1, 'title' => 'Redaktion'],
            ['uid' => 2, 'title' => 'Redaktion 2'],
            false,
        );

        $group = $this->subject->findGroup('Redaktion');
        self::assertSame(1, $group['uid'] ?? null);

        // By numeric uid
        $group2 = $this->subject->findGroup('2');
        self::assertSame(2, $group2['uid'] ?? null);

        // Not found
        self::assertNull($this->subject->findGroup('Unknown'));
    }

    #[Test]
    public function findGroupsWithModuleFiltersModules(): void
    {
        $this->result->method('fetchAllAssociative')->willReturn([
            ['uid' => 1, 'title' => 'G1', 'groupMods' => 'web_layout,records'],
            ['uid' => 2, 'title' => 'G2', 'groupMods' => 'media_management'],
        ]);

        $filtered = $this->subject->findGroupsWithModule('web_layout');
        self::assertCount(1, $filtered);
        self::assertSame('G1', $filtered[0]['title']);
    }

    #[Test]
    public function contentTypesExtractsTypesAndFiltersDivs(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] = [
            ['label' => 'Standard Text', 'value' => 'text'],
            ['label' => 'Divider', 'value' => '--div--'],
            ['label' => 'Empty value', 'value' => ''],
            ['label' => 'Header', 'value' => 'header'],
        ];

        self::assertSame(['text', 'header'], $this->subject->contentTypes());
    }

    #[Test]
    public function grantUnownedPagesReturnsEarlyWhenGroupUidIsZeroOrLess(): void
    {
        self::assertSame(0, $this->subject->grantUnownedPages(0));
        self::assertSame(0, $this->subject->grantUnownedPages(-1));
    }

    #[Test]
    public function grantUnownedPagesExecutesUpdateStatement(): void
    {
        $this->queryBuilder->method('executeStatement')->willReturn(7);

        self::assertSame(7, $this->subject->grantUnownedPages(5));
    }

    #[Test]
    public function countPagesOwnedByOtherGroupsReturnsInteger(): void
    {
        $this->result->method('fetchOne')->willReturn('3');

        self::assertSame(3, $this->subject->countPagesOwnedByOtherGroups(5));
    }

    #[Test]
    public function findUserReturnsUserOrNull(): void
    {
        $this->result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['uid' => 10, 'username' => 'jana'],
            false,
        );

        self::assertSame(10, $this->subject->findUser('jana')['uid'] ?? null);
        self::assertNull($this->subject->findUser('nobody'));
    }

    #[Test]
    public function findEditorsReturnsList(): void
    {
        $this->result->method('fetchAllAssociative')->willReturn([
            ['uid' => 10, 'username' => 'jana'],
        ]);

        $editors = $this->subject->findEditors();
        self::assertCount(1, $editors);
        self::assertSame('jana', $editors[0]['username']);
    }

    #[Test]
    public function findWorkspaceByTitleFindsMatchingWorkspace(): void
    {
        $this->result->method('fetchAllAssociative')->willReturn([
            ['uid' => 1, 'title' => 'WS1'],
            ['uid' => 2, 'title' => 'WS2'],
        ]);

        self::assertSame(2, $this->subject->findWorkspaceByTitle('WS2')['uid'] ?? null);
        self::assertNull($this->subject->findWorkspaceByTitle('NonExistent'));
    }

    #[Test]
    public function addWorkspaceOwnersReturnsEarlyWhenWorkspaceNotFound(): void
    {
        $this->result->method('fetchAssociative')->willReturn(false);
        $this->subject->addWorkspaceOwners(99, ['be_groups_1']);
        self::assertTrue(true);
    }

    #[Test]
    public function addWorkspaceOwnersReturnsEarlyWhenAlreadyOwned(): void
    {
        $this->result->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'adminusers' => 'be_groups_1,be_groups_2',
        ]);
        $this->subject->addWorkspaceOwners(1, ['be_groups_1']);
        self::assertTrue(true);
    }

    #[Test]
    public function dataHandlerMutationsCreateAndModifyRecords(): void
    {
        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->method('start')->willReturnCallback(static function (array $data, array $cmdMap) use ($dataHandler): void {
            $table = array_key_first($data);
            if ($table !== null && is_array($data[$table])) {
                $placeholder = (string)array_key_first($data[$table]);
                $dataHandler->substNEWwithIDs[$placeholder] = 42;
            }
        });
        for ($i = 0; $i < 9; $i++) {
            GeneralUtility::addInstance(DataHandler::class, $dataHandler);
        }

        // createRootPage
        $pageUid = $this->subject->createRootPage('Neue Startseite');
        self::assertSame(42, $pageUid);

        // createEditorGroup
        $groupUid = $this->subject->createEditorGroup('Redaktion', 'web_layout', [1]);
        self::assertSame(42, $groupUid);

        // createInheritingGroup
        $inheritingUid = $this->subject->createInheritingGroup('Sport', 10);
        self::assertSame(42, $inheritingUid);

        // addModulesToGroup
        $this->subject->addModulesToGroup(1, 'records', ['web_layout']);

        // createWorkspace
        $wsUid = $this->subject->createWorkspace('Redaktion', 'Beschreibung');
        self::assertSame(42, $wsUid);

        // allowPublishingOutsidePublishStage
        $this->subject->allowPublishingOutsidePublishStage(1, 1);

        // disallowLiveEditing
        $this->subject->disallowLiveEditing(10, 1);

        // disallowLiveEditingForGroup
        $this->subject->disallowLiveEditingForGroup(5, 1);

        // addUserToGroup
        $this->subject->addUserToGroup(10, '1', 2);
    }

    #[Test]
    public function createEditorCreatesUserAndUpdatesWorkspaceId(): void
    {
        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->method('start')->willReturnCallback(static function (array $data, array $cmdMap) use ($dataHandler): void {
            $table = array_key_first($data);
            if ($table !== null && is_array($data[$table])) {
                $placeholder = (string)array_key_first($data[$table]);
                $dataHandler->substNEWwithIDs[$placeholder] = 77;
            }
        });
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $this->connection->expects(self::once())
            ->method('update')
            ->with('be_users', ['workspace_id' => 5], ['uid' => 77]);

        $uid = $this->subject->createEditor('jana', 'Password.1', 1, 5);
        self::assertSame(77, $uid);
    }

    #[Test]
    public function addWorkspaceOwnersAppendsNewOwnersViaDataHandler(): void
    {
        $this->result->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'adminusers' => 'be_groups_1',
        ]);

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->expects(self::once())
            ->method('start')
            ->with(['sys_workspace' => [1 => ['adminusers' => 'be_groups_1,be_groups_2']]], []);
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $this->subject->addWorkspaceOwners(1, ['be_groups_2']);
    }
}
