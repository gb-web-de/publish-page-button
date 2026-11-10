<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

/**
 * Die uids, die ein Setup-Lauf bisher gefunden oder angelegt hat, nach Titel.
 *
 * Beim Planen sind nur Namen bekannt; welche uid eine neue Gruppe bekommt, weiss erst der Lauf.
 */
final class SetupContext
{
    /** @var array<string, int> */
    private array $groups = [];

    /** @var array<string, int> */
    private array $workspaces = [];

    /** @var list<string> */
    private array $messages = [];

    public function rememberGroup(string $title, int $uid): void
    {
        $this->groups[$title] = $uid;
    }

    public function group(string $title): int
    {
        return $this->groups[$title] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public function rememberWorkspace(string $title, int $uid): void
    {
        $this->workspaces[$title] = $uid;
    }

    public function workspace(string $title): int
    {
        return $this->workspaces[$title] ?? 0;
    }

    public function report(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}
