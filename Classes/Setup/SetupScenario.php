<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

/**
 * Die Ausgangslagen, die `publishpage:setup` kennt.
 *
 * Jede ist eine andere Antwort auf "Was ist in dieser Installation schon da?" - denn das entscheidet, was
 * gefahrlos geschrieben werden darf. Eine leere Installation kann nichts verlieren; ein ueber Jahre
 * gewachsener Gruppenbaum hat Rechte, die ein Setup-Befehl nicht umschreiben darf.
 */
enum SetupScenario: string
{
    /** Leere Datenbank: Seitenbaum, Gruppe und Workspace werden angelegt. */
    case Fresh = 'fresh';

    /** Eine Redaktionsgruppe mit frei waehlbarem Namen, ein Workspace. */
    case SingleGroup = 'single-group';

    /** Mehrere Gruppen, jede mit eigenem Workspace. */
    case GroupPerWorkspace = 'group-per-workspace';

    /** Bestehender (grosser) Gruppenbaum: Gruppen bleiben, wie sie sind, und duerfen im Workspace veroeffentlichen. */
    case ExistingGroups = 'existing-groups';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Gruene Wiese / leere Datenbank',
            self::SingleGroup => 'Eine Redaktionsgruppe (Name frei waehlbar) mit einem Workspace',
            self::GroupPerWorkspace => 'Mehrere Gruppen, jede mit eigenem Workspace',
            self::ExistingGroups => 'Bestehender Gruppenbaum - bleibt, wie er ist',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scenario): string => $scenario->value, self::cases());
    }
}
