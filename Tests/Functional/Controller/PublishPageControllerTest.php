<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Functional\Controller;

use GbWebDe\PublishPageButton\Command\SetupCommand;
use GbWebDe\PublishPageButton\Controller\PublishPageController;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PublishPageControllerTest extends FunctionalTestCase
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
        $this->importCSVDataSet(dirname(__DIR__) . '/Setup/Fixtures/be_users.csv');
        $this->importCSVDataSet(dirname(__DIR__) . '/Setup/Fixtures/pages.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function publishActionPublishesPageAndRedirectsWithFlashMessage(): void
    {
        // 1. Run setup to configure Redaktion group, workspace and editor
        $tester = new CommandTester($this->get(SetupCommand::class));
        $tester->execute(['scenario' => 'single-group', '--editor' => ['jana'], '--editor-password' => 'Password.1']);

        $workspaces = $this->getConnectionPool()->getQueryBuilderForTable('sys_workspace')
            ->select('uid')
            ->from('sys_workspace')
            ->where('title = "Redaktion"')
            ->executeQuery()
            ->fetchAssociative();
        $workspaceUid = (int)$workspaces['uid'];

        $editor = $this->getConnectionPool()->getQueryBuilderForTable('be_users')
            ->select('uid')
            ->from('be_users')
            ->where('username = "jana"')
            ->executeQuery()
            ->fetchAssociative();

        $jana = $this->setUpBackendUser((int)$editor['uid']);
        $jana->setWorkspace($workspaceUid);
        $GLOBALS['BE_USER'] = $jana;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($jana);

        // 2. Make workspace changes on page 2
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'pages' => [2 => ['title' => 'Page 2 in Workspace']],
        ], [], $jana);
        $dataHandler->process_datamap();

        // 3. Request publishAction
        $request = (new ServerRequest('https://example.org/typo3/publish-page-button/publish'))
            ->withQueryParams(['id' => '2']);

        $controller = $this->get(PublishPageController::class);
        $response = $controller->publishAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/typo3/module/web/layout', $response->getHeaderLine('location'));
        self::assertStringContainsString('id=2', $response->getHeaderLine('location'));

        // Verify page title is published to live
        $page = $this->getConnectionPool()->getQueryBuilderForTable('pages')
            ->select('title')
            ->from('pages')
            ->where('uid = 2')
            ->executeQuery()
            ->fetchAssociative();
        self::assertSame('Page 2 in Workspace', $page['title']);

        // Verify Flash Message
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush();
        self::assertCount(1, $messages);
        self::assertSame(ContextualFeedbackSeverity::OK, $messages[0]->getSeverity());
        self::assertMatchesRegularExpression('/All changes on this page are now live|Alle (A|ä)nderungen dieser Seite sind jetzt live/i', $messages[0]->getMessage());
    }

    #[Test]
    public function publishActionReturnsInfoWhenNothingToPublish(): void
    {
        $tester = new CommandTester($this->get(SetupCommand::class));
        $tester->execute(['scenario' => 'single-group', '--editor' => ['jana'], '--editor-password' => 'Password.1']);

        $workspaces = $this->getConnectionPool()->getQueryBuilderForTable('sys_workspace')
            ->select('uid')
            ->from('sys_workspace')
            ->where('title = "Redaktion"')
            ->executeQuery()
            ->fetchAssociative();

        $editor = $this->getConnectionPool()->getQueryBuilderForTable('be_users')
            ->select('uid')
            ->from('be_users')
            ->where('username = "jana"')
            ->executeQuery()
            ->fetchAssociative();

        $jana = $this->setUpBackendUser((int)$editor['uid']);
        $jana->setWorkspace((int)$workspaces['uid']);
        $GLOBALS['BE_USER'] = $jana;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($jana);

        $request = (new ServerRequest('https://example.org/typo3/publish-page-button/publish'))
            ->withQueryParams(['id' => '2']);

        $controller = $this->get(PublishPageController::class);
        $response = $controller->publishAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);

        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush();
        self::assertCount(1, $messages);
        self::assertSame(ContextualFeedbackSeverity::INFO, $messages[0]->getSeverity());
        self::assertMatchesRegularExpression('/There is nothing new to publish on this page|Auf dieser Seite gibt es nichts Neues zu ver(o|ö)ffentlichen|nichts Neues zu ver(o|ö)ffentlichen/i', $messages[0]->getMessage());
    }
}
