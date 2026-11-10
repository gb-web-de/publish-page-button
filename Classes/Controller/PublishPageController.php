<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Controller;

use GbWebDe\PublishPageButton\Event\AfterPublishPageEvent;
use GbWebDe\PublishPageButton\Event\BeforePublishPageEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * "Seite veroeffentlichen" im Seitenmodul und "Datensatz veroeffentlichen" in FormEngine / RecordList:
 * Bringt Workspace-Aenderungen mit einem Klick live.
 */
#[AsController]
final readonly class PublishPageController
{
    public function __construct(
        // shortcut: WorkspacePublishGate ist @internal; bricht ein Core-Update sie, checkWorkspace() selbst auswerten
        private WorkspacePublishGate $publishGate,
        private WorkspaceService $workspaceService,
        private FlashMessageService $flashMessageService,
        private UriBuilder $uriBuilder,
        private EventDispatcherInterface $eventDispatcher,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {
    }

    public function publishAction(ServerRequestInterface $request): ResponseInterface
    {
        $table = (string)($request->getQueryParams()['table'] ?? '');
        $id = (int)($request->getQueryParams()['id'] ?? 0);
        $isRecord = $table !== '';

        if ($isRecord) {
            $result = $this->publishRecord($GLOBALS['BE_USER'], $table, $id);
            $message = match (true) {
                is_array($result) => new FlashMessage(
                    implode("\n", $result),
                    $this->translate('flashMessage.publishFailed.title', 'Publishing failed'),
                    ContextualFeedbackSeverity::ERROR
                ),
                $result === 0 => new FlashMessage(
                    $this->translate('flashMessage.recordCurrent.message', 'There are no unpublished changes for this record.'),
                    $this->translate('flashMessage.recordCurrent.title', 'Record is up to date'),
                    ContextualFeedbackSeverity::INFO
                ),
                default => new FlashMessage(
                    $this->translate('flashMessage.recordPublished.message', 'The record is now live.'),
                    $this->translate('flashMessage.recordPublished.title', 'Record published'),
                    ContextualFeedbackSeverity::OK
                ),
            };
        } else {
            $result = $this->publish($GLOBALS['BE_USER'], $id);
            $message = match (true) {
                is_array($result) => new FlashMessage(
                    implode("\n", $result),
                    $this->translate('flashMessage.publishFailed.title', 'Publishing failed'),
                    ContextualFeedbackSeverity::ERROR
                ),
                $result === 0 => new FlashMessage(
                    $this->translate('flashMessage.pageCurrent.message', 'There is nothing new to publish on this page.'),
                    $this->translate('flashMessage.pageCurrent.title', 'Page is up to date'),
                    ContextualFeedbackSeverity::INFO
                ),
                default => new FlashMessage(
                    $this->translate('flashMessage.pagePublished.message', 'All changes on this page are now live.'),
                    $this->translate('flashMessage.pagePublished.title', 'Page published'),
                    ContextualFeedbackSeverity::OK
                ),
            };
        }

        $message->setStoreInSession(true);
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue($message);

        $returnUrl = GeneralUtility::sanitizeLocalUrl((string)($request->getQueryParams()['returnUrl'] ?? ''), $request);
        if ($returnUrl !== '') {
            return new RedirectResponse($returnUrl);
        }

        $fallbackRoute = $table === 'pages' || !$isRecord ? 'web_layout' : 'web_list';
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute($fallbackRoute, ['id' => $id]));
    }

    /** @return int|list<string> Anzahl veroeffentlichter Datensaetze oder Fehler */
    public function publish(BackendUserAuthentication $user, int $pageId): int|array
    {
        $workspace = $user->workspace;
        if ($workspace <= 0 || $pageId <= 0 || !$this->publishGate->isGranted($user, $workspace)
            || (($user->workspaceRec['publish_access'] ?? 0) & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE)
        ) {
            return [$this->translate('error.noPermission', 'No permission to publish in this workspace.')];
        }
        $cmdMap = [];
        foreach ($this->workspaceService->selectVersionsInWorkspace($workspace, -99, $pageId, 0, 'tables_modify') as $table => $items) {
            foreach ($items as $item) {
                $cmdMap[$table][$item['t3ver_oid'] ?: $item['uid']]['version'] = ['action' => 'publish', 'swapWith' => $item['uid']];
            }
        }

        /** @var BeforePublishPageEvent $beforeEvent */
        $beforeEvent = $this->eventDispatcher->dispatch(new BeforePublishPageEvent($pageId, $workspace, $user, $cmdMap));
        if ($beforeEvent->isPropagationStopped()) {
            return [$beforeEvent->getCancelReason() ?: $this->translate('error.cancelled', 'Publishing was cancelled by an event listener.')];
        }
        $cmdMap = $beforeEvent->getCmdMap();

        if ($cmdMap === []) {
            return 0;
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdMap, $user);
        $dataHandler->process_cmdmap();
        $result = $dataHandler->errorLog ?: array_sum(array_map('count', $cmdMap));

        /** @var AfterPublishPageEvent $afterEvent */
        $afterEvent = $this->eventDispatcher->dispatch(new AfterPublishPageEvent($pageId, $workspace, $user, $cmdMap, $result));
        return $afterEvent->getResult();
    }

    /** @return int|list<string> Anzahl veroeffentlichter Datensaetze oder Fehler */
    public function publishRecord(BackendUserAuthentication $user, string $table, int $recordId): int|array
    {
        $workspace = $user->workspace;
        if ($workspace <= 0 || $recordId <= 0 || !$this->publishGate->isGranted($user, $workspace)
            || (($user->workspaceRec['publish_access'] ?? 0) & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE)
        ) {
            return [$this->translate('error.noPermission', 'No permission to publish in this workspace.')];
        }

        if (!$this->tcaSchemaFactory->has($table) || !$this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
            return [$this->translate('error.tableNotWorkspaceAware', 'The table is not workspace-enabled.')];
        }

        $record = BackendUtility::getRecord($table, $recordId);
        if (!$record) {
            return [$this->translate('error.recordNotFound', 'Record not found.')];
        }

        $recordWsid = (int)($record['t3ver_wsid'] ?? 0);
        $recordOid = (int)($record['t3ver_oid'] ?? 0);

        if ($recordWsid === $workspace) {
            $liveUid = $recordOid ?: (int)$record['uid'];
            $versionUid = (int)$record['uid'];
        } elseif ($recordWsid === 0) {
            $workspaceVersion = BackendUtility::getWorkspaceVersionOfRecord($workspace, $table, (int)$record['uid']);
            if (!$workspaceVersion) {
                return 0;
            }
            $liveUid = (int)$record['uid'];
            $versionUid = (int)$workspaceVersion['uid'];
        } else {
            return [$this->translate('error.foreignWorkspace', 'Record belongs to a different workspace.')];
        }

        $liveRecord = $liveUid !== (int)$record['uid'] ? BackendUtility::getRecord($table, $liveUid) : $record;
        $pageId = (int)($liveRecord['pid'] ?? $record['pid'] ?? 0);
        if ($table === 'pages') {
            $pageId = $liveUid;
        }

        $cmdMap = [
            $table => [
                $liveUid => [
                    'version' => [
                        'action' => 'publish',
                        'swapWith' => $versionUid,
                    ],
                ],
            ],
        ];

        /** @var BeforePublishPageEvent $beforeEvent */
        $beforeEvent = $this->eventDispatcher->dispatch(new BeforePublishPageEvent($pageId, $workspace, $user, $cmdMap, $table, $liveUid));
        if ($beforeEvent->isPropagationStopped()) {
            return [$beforeEvent->getCancelReason() ?: $this->translate('error.cancelled', 'Publishing was cancelled by an event listener.')];
        }
        $cmdMap = $beforeEvent->getCmdMap();

        if ($cmdMap === []) {
            return 0;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdMap, $user);
        $dataHandler->process_cmdmap();
        $result = $dataHandler->errorLog ?: array_sum(array_map('count', $cmdMap));

        /** @var AfterPublishPageEvent $afterEvent */
        $afterEvent = $this->eventDispatcher->dispatch(new AfterPublishPageEvent($pageId, $workspace, $user, $cmdMap, $result, $table, $liveUid));
        return $afterEvent->getResult();
    }

    private function translate(string $key, string $default): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            $translated = $lang->sL('LLL:EXT:publish_page_button/Resources/Private/Language/locallang.xlf:' . $key);
            if ($translated !== '') {
                return $translated;
            }
        }
        return $default;
    }
}
