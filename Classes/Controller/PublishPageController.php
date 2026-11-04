<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * "Seite veroeffentlichen" im Seitenmodul: bringt alle Workspace-Aenderungen einer Seite (Seite selbst,
 * Uebersetzungen, Inhaltselemente, Bilder) mit einem Klick live. Gleiche Logik wie der Publish-Knopf der
 * Workspace-Vorschau (WorkspacesAjaxController::publishPageDirectly), die dort privat ist.
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
    ) {
    }

    public function publishAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = (int)($request->getQueryParams()['id'] ?? 0);
        $result = $this->publish($GLOBALS['BE_USER'], $pageId);
        $message = match (true) {
            is_array($result) => new FlashMessage(implode("\n", $result), 'Veroeffentlichen fehlgeschlagen', ContextualFeedbackSeverity::ERROR),
            $result === 0 => new FlashMessage('Auf dieser Seite gibt es nichts Neues zu veroeffentlichen.', 'Seite ist aktuell', ContextualFeedbackSeverity::INFO),
            default => new FlashMessage('Alle Aenderungen dieser Seite sind jetzt live.', 'Seite veroeffentlicht', ContextualFeedbackSeverity::OK),
        };
        $message->setStoreInSession(true);
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue($message);
        $returnUrl = GeneralUtility::sanitizeLocalUrl((string)($request->getQueryParams()['returnUrl'] ?? ''), $request);
        return new RedirectResponse($returnUrl ?: (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageId]));
    }

    /** @return int|list<string> Anzahl veroeffentlichter Datensaetze oder Fehler */
    public function publish(BackendUserAuthentication $user, int $pageId): int|array
    {
        $workspace = $user->workspace;
        if ($workspace <= 0 || $pageId <= 0 || !$this->publishGate->isGranted($user, $workspace)
            || (($user->workspaceRec['publish_access'] ?? 0) & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE)
        ) {
            return ['Keine Berechtigung zum Veroeffentlichen in diesem Workspace.'];
        }
        $cmdMap = [];
        foreach ($this->workspaceService->selectVersionsInWorkspace($workspace, -99, $pageId, 0, 'tables_modify') as $table => $items) {
            foreach ($items as $item) {
                $cmdMap[$table][$item['t3ver_oid'] ?: $item['uid']]['version'] = ['action' => 'publish', 'swapWith' => $item['uid']];
            }
        }
        if ($cmdMap === []) {
            return 0;
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdMap, $user);
        $dataHandler->process_cmdmap();
        return $dataHandler->errorLog ?: array_sum(array_map('count', $cmdMap));
    }
}
