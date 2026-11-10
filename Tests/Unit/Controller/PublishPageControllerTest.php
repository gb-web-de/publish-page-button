<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Controller;

use GbWebDe\PublishPageButton\Controller\PublishPageController;
use GbWebDe\PublishPageButton\Event\AfterPublishPageEvent;
use GbWebDe\PublishPageButton\Event\BeforePublishPageEvent;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PublishPageControllerTest extends UnitTestCase
{
    private WorkspacePublishGate $publishGate;
    private WorkspaceService $workspaceService;
    private FlashMessageService $flashMessageService;
    private FlashMessageQueue $messageQueue;
    private UriBuilder $uriBuilder;
    private EventDispatcherInterface $eventDispatcher;
    private PublishPageController $subject;

    protected function setUp(): void
    {
        $this->resetSingletonInstances = true;
        parent::setUp();

        $this->publishGate = $this->createMock(WorkspacePublishGate::class);
        $this->workspaceService = $this->createMock(WorkspaceService::class);
        $this->flashMessageService = $this->createMock(FlashMessageService::class);
        $this->messageQueue = $this->createMock(FlashMessageQueue::class);
        $this->flashMessageService->method('getMessageQueueByIdentifier')->willReturn($this->messageQueue);
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->subject = new PublishPageController(
            $this->publishGate,
            $this->workspaceService,
            $this->flashMessageService,
            $this->uriBuilder,
            $this->eventDispatcher,
        );
    }

    #[Test]
    public function publishRejectsUserWithoutWorkspace(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 0;

        $result = $this->subject->publish($user, 42);

        self::assertSame(['Keine Berechtigung zum Veroeffentlichen in diesem Workspace.'], $result);
    }

    #[Test]
    public function publishRejectsInvalidPageId(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;

        $result = $this->subject->publish($user, 0);

        self::assertSame(['Keine Berechtigung zum Veroeffentlichen in diesem Workspace.'], $result);
    }

    #[Test]
    public function publishRejectsWhenGateRefusesPermission(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(false);

        $result = $this->subject->publish($user, 42);

        self::assertSame(['Keine Berechtigung zum Veroeffentlichen in diesem Workspace.'], $result);
    }

    #[Test]
    public function publishRejectsWorkspaceThatPublishesOnlyFromPublishStage(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $user->workspaceRec = ['publish_access' => WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE];
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(true);

        $result = $this->subject->publish($user, 42);

        self::assertSame(['Keine Berechtigung zum Veroeffentlichen in diesem Workspace.'], $result);
    }

    #[Test]
    public function publishReturnsZeroWhenNothingToPublish(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $user->workspaceRec = ['publish_access' => 0];
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(true);
        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([]);

        $result = $this->subject->publish($user, 42);

        self::assertSame(0, $result);
    }

    #[Test]
    public function publishExecutesDataHandlerAndReturnsCount(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $user->workspaceRec = ['publish_access' => 0];
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(true);

        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([
            'pages' => [
                ['uid' => 10, 't3ver_oid' => 42],
            ],
            'tt_content' => [
                ['uid' => 20, 't3ver_oid' => 0], // falls t3ver_oid 0 ist, nimmt er uid
            ],
        ]);

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->errorLog = [];
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $result = $this->subject->publish($user, 42);

        self::assertSame(2, $result);
    }

    #[Test]
    public function publishReturnsErrorsIfDataHandlerFails(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $user->workspaceRec = ['publish_access' => 0];
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(true);

        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([
            'pages' => [['uid' => 10, 't3ver_oid' => 42]],
        ]);

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->errorLog = ['DataHandler database error'];
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $result = $this->subject->publish($user, 42);

        self::assertSame(['DataHandler database error'], $result);
    }

    #[Test]
    public function publishActionRedirectsWithOkFlashMessage(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $GLOBALS['BE_USER'] = $user;
        $this->publishGate->method('isGranted')->willReturn(true);
        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([
            'pages' => [['uid' => 10, 't3ver_oid' => 42]],
        ]);

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->errorLog = [];
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $this->messageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(static function (FlashMessage $message): bool {
                return $message->getSeverity() === ContextualFeedbackSeverity::OK
                    && str_contains($message->getMessage(), 'Alle Aenderungen');
            }));

        $this->uriBuilder->method('buildUriFromRoute')
            ->with('web_layout', ['id' => 42])
            ->willReturn(new Uri('/typo3/module/web/layout?id=42'));

        $request = (new ServerRequest('https://example.org/typo3/publish'))
            ->withQueryParams(['id' => '42']);

        $response = $this->subject->publishAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/typo3/module/web/layout?id=42', $response->getHeaderLine('location'));
    }

    #[Test]
    public function publishActionRedirectsWithInfoFlashMessageWhenNothingNew(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $GLOBALS['BE_USER'] = $user;
        $this->publishGate->method('isGranted')->willReturn(true);
        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([]);

        $this->messageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(static function (FlashMessage $message): bool {
                return $message->getSeverity() === ContextualFeedbackSeverity::INFO
                    && str_contains($message->getMessage(), 'nichts Neues');
            }));

        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/fallback'));

        $normalizedParams = $this->createMock(\TYPO3\CMS\Core\Http\NormalizedParams::class);
        $normalizedParams->method('getRequestHost')->willReturn('https://example.org');
        $normalizedParams->method('getSiteUrl')->willReturn('https://example.org/');
        $normalizedParams->method('getSitePath')->willReturn('/');

        $request = (new ServerRequest('https://example.org/typo3/publish'))
            ->withAttribute('normalizedParams', $normalizedParams)
            ->withQueryParams(['id' => '42', 'returnUrl' => 'https://example.org/typo3/custom/return']);

        $response = $this->subject->publishAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://example.org/typo3/custom/return', $response->getHeaderLine('location'));
    }

    #[Test]
    public function publishActionRedirectsWithErrorFlashMessageOnFailure(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 0; // Live workspace -> denied
        $GLOBALS['BE_USER'] = $user;

        $this->messageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(static function (FlashMessage $message): bool {
                return $message->getSeverity() === ContextualFeedbackSeverity::ERROR
                    && str_contains($message->getMessage(), 'Keine Berechtigung');
            }));

        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/fallback'));

        $request = (new ServerRequest('https://example.org/typo3/publish'))
            ->withQueryParams(['id' => '42']);

        $response = $this->subject->publishAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/typo3/fallback', $response->getHeaderLine('location'));
    }

    #[Test]
    public function publishDispatchesBeforeAndAfterEvents(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(true);

        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([
            'pages' => [
                ['uid' => 10, 't3ver_oid' => 42],
            ],
        ]);

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->errorLog = [];
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $dispatchedEvents = [];
        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$dispatchedEvents) {
            $dispatchedEvents[] = $event;
            return $event;
        });

        $result = $this->subject->publish($user, 42);

        self::assertSame(1, $result);
        self::assertCount(2, $dispatchedEvents);
        self::assertInstanceOf(BeforePublishPageEvent::class, $dispatchedEvents[0]);
        self::assertSame(42, $dispatchedEvents[0]->getPageId());
        self::assertSame(1, $dispatchedEvents[0]->getWorkspaceId());
        self::assertSame($user, $dispatchedEvents[0]->getUser());

        self::assertInstanceOf(AfterPublishPageEvent::class, $dispatchedEvents[1]);
        self::assertSame(42, $dispatchedEvents[1]->getPageId());
        self::assertSame(1, $dispatchedEvents[1]->getResult());
        self::assertFalse($dispatchedEvents[1]->hasErrors());
    }

    #[Test]
    public function publishStopsWhenBeforePublishPageEventIsCancelled(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(true);

        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([
            'pages' => [
                ['uid' => 10, 't3ver_oid' => 42],
            ],
        ]);

        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) {
            if ($event instanceof BeforePublishPageEvent) {
                $event->cancel('Freigabeprüfung nicht bestanden.');
            }
            return $event;
        });

        $result = $this->subject->publish($user, 42);

        self::assertSame(['Freigabeprüfung nicht bestanden.'], $result);
    }

    #[Test]
    public function publishAllowsBeforePublishPageEventToModifyCmdMap(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(true);

        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([
            'pages' => [
                ['uid' => 10, 't3ver_oid' => 42],
            ],
        ]);

        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) {
            if ($event instanceof BeforePublishPageEvent) {
                $event->setCmdMap([]);
            }
            return $event;
        });

        $result = $this->subject->publish($user, 42);

        self::assertSame(0, $result);
    }

    #[Test]
    public function publishAllowsAfterPublishPageEventToModifyResult(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->workspace = 1;
        $this->publishGate->method('isGranted')->with($user, 1)->willReturn(true);

        $this->workspaceService->method('selectVersionsInWorkspace')->willReturn([
            'pages' => [
                ['uid' => 10, 't3ver_oid' => 42],
            ],
        ]);

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->errorLog = [];
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) {
            if ($event instanceof AfterPublishPageEvent) {
                $event->setResult(99);
            }
            return $event;
        });

        $result = $this->subject->publish($user, 42);

        self::assertSame(99, $result);
    }
}
