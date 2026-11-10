<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Event;

use Psr\EventDispatcher\StoppableEventInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Dispatched directly before publishing workspace versions to live.
 * Can be dispatched for an entire page or for a single workspace record.
 *
 * Listeners can:
 * - inspect page ID, workspace and backend user
 * - inspect whether it's a single record publication (getTable(), getRecordUid(), isSingleRecord())
 * - inspect and modify the $cmdMap to customize what gets published
 * - stop/cancel the publication via cancel() or by stopping propagation
 */
final class BeforePublishPageEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;
    private ?string $cancelReason = null;

    /**
     * @param array<string, array<int|string, array{version: array{action: string, swapWith: int}}>> $cmdMap
     */
    public function __construct(
        private readonly int $pageId,
        private readonly int $workspaceId,
        private readonly BackendUserAuthentication $user,
        private array $cmdMap,
        private readonly ?string $table = null,
        private readonly ?int $recordUid = null,
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
     * @param array<string, array<int|string, array{version: array{action: string, swapWith: int}}>> $cmdMap
     */
    public function setCmdMap(array $cmdMap): void
    {
        $this->cmdMap = $cmdMap;
    }

    public function getTable(): ?string
    {
        return $this->table;
    }

    public function getRecordUid(): ?int
    {
        return $this->recordUid;
    }

    public function isSingleRecord(): bool
    {
        return $this->table !== null && $this->recordUid !== null;
    }

    public function cancel(string $reason = 'Publishing was cancelled by an event listener.'): void
    {
        $this->propagationStopped = true;
        $this->cancelReason = $reason;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }
}
