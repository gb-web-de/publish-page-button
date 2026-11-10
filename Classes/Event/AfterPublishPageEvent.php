<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Event;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Dispatched directly after publishing workspace versions of a page to live.
 *
 * Listeners can:
 * - inspect page ID, workspace and backend user
 * - inspect the cmdMap that was processed
 * - inspect and modify the result (record count or error list)
 * - trigger follow-up actions (cache clearing, webhooks, notifications)
 */
final class AfterPublishPageEvent
{
    /**
     * @param array<string, array<int|string, array{version: array{action: string, swapWith: int}}>> $cmdMap
     * @param int|list<string> $result Number of published records or list of error messages
     */
    public function __construct(
        private readonly int $pageId,
        private readonly int $workspaceId,
        private readonly BackendUserAuthentication $user,
        private readonly array $cmdMap,
        private int|array $result,
    ) {
    }

    public function getPageId(): int
    {
        return $this->pageId;
    }

    public function getWorkspaceId(): int
    {
        return $this->workspaceId;
    }

    public function getUser(): BackendUserAuthentication
    {
        return $this->user;
    }

    /**
     * @return array<string, array<int|string, array{version: array{action: string, swapWith: int}}>>
     */
    public function getCmdMap(): array
    {
        return $this->cmdMap;
    }

    /**
     * @return int|list<string>
     */
    public function getResult(): int|array
    {
        return $this->result;
    }

    /**
     * @param int|list<string> $result
     */
    public function setResult(int|array $result): void
    {
        $this->result = $result;
    }

    public function hasErrors(): bool
    {
        return is_array($this->result);
    }
}
