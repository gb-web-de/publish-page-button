<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

/**
 * Eine Zeile des Plans, den `publishpage:setup` vor dem Schreiben zeigt.
 *
 * Die Beschreibung steht beim Planen fest, `--dry-run` zeigt also genau, was ein echter Lauf taete. Die
 * Aktion laeuft spaeter und liest uids, die ein frueherer Schritt angelegt hat, aus dem SetupContext.
 */
final class SetupStep
{
    /**
     * @param \Closure(SetupContext): void $action
     */
    public function __construct(
        public readonly string $description,
        private readonly \Closure $action,
        public readonly bool $changesSomething = true,
    ) {
    }

    public static function unchanged(string $description): self
    {
        return new self($description, static function (): void {
            // Steht im Plan, damit niemand raetseln muss, ob es angesehen wurde.
        }, false);
    }

    public function run(SetupContext $context): void
    {
        ($this->action)($context);
    }
}
