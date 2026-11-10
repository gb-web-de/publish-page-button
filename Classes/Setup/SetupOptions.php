<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

/**
 * Alles, was `publishpage:setup` gesagt bekam, einmal geprueft und normalisiert.
 *
 * Aus einfachen Werten statt aus einem InputInterface gebaut: So sind die Regeln - welches Szenario
 * welche Option braucht, was die Vorgaben sind - ohne Konsole testbar, und der Wizard landet an derselben
 * Stelle wie ein Aufruf mit Optionen.
 */
final class SetupOptions
{
    public const string DEFAULT_GROUP = 'Redaktion';
    public const string DEFAULT_WORKSPACE = 'Redaktion';

    /** be_groups.title ist varchar(50). */
    private const int MAX_GROUP_TITLE_LENGTH = 50;

    /**
     * @param list<string> $groups Redaktionsgruppen, die angelegt oder uebernommen werden
     * @param list<string> $attachTo existing-groups: bestehende Gruppen (Titel oder uid)
     * @param array<string, string> $editors Benutzername => Titel der Gruppe, in die er kommt
     */
    private function __construct(
        public readonly SetupScenario $scenario,
        public readonly array $groups,
        public readonly ?string $workspace,
        public readonly array $attachTo,
        public readonly bool $attachToEditors,
        public readonly string $siteBase,
        public readonly bool $dryRun,
        public readonly array $editors,
        public readonly ?string $editorPassword,
    ) {
    }

    /**
     * @param array{
     *     group?: string|list<string>|null,
     *     workspace?: string|null,
     *     attach-to?: string|list<string>|null,
     *     attach-to-editors?: bool|null,
     *     site-base?: string|null,
     *     dry-run?: bool|null,
     *     editor?: string|list<string>|null,
     *     editor-password?: string|null,
     * } $values
     */
    public static function fromArray(SetupScenario $scenario, array $values): self
    {
        $groups = CommaList::parse($values['group'] ?? []);
        if ($groups === [] && $scenario !== SetupScenario::GroupPerWorkspace && $scenario !== SetupScenario::ExistingGroups) {
            $groups = [self::DEFAULT_GROUP];
        }

        $workspace = trim((string)($values['workspace'] ?? ''));
        $explicitWorkspace = $workspace === '' ? null : $workspace;

        $options = new self(
            $scenario,
            $groups,
            $explicitWorkspace ?? match ($scenario) {
                SetupScenario::GroupPerWorkspace => null,
                SetupScenario::ExistingGroups => self::DEFAULT_WORKSPACE,
                default => $groups[0],
            },
            CommaList::parse($values['attach-to'] ?? []),
            (bool)($values['attach-to-editors'] ?? false),
            trim((string)($values['site-base'] ?? '')) ?: '/',
            (bool)($values['dry-run'] ?? false),
            self::parseEditors(CommaList::parse($values['editor'] ?? []), $groups),
            ($values['editor-password'] ?? '') === '' ? null : (string)$values['editor-password'],
        );
        $options->validate($explicitWorkspace);

        return $options;
    }

    /**
     * Welcher Workspace welchen Gruppen gehoert: einer fuer alle oder einer je Gruppe.
     *
     * @return array<string, list<string>> Workspace-Titel => Titel der Besitzergruppen
     */
    public function workspacesWithOwnerGroups(): array
    {
        if ($this->scenario === SetupScenario::GroupPerWorkspace) {
            return array_combine($this->groups, array_map(static fn (string $group): array => [$group], $this->groups));
        }

        return [(string)$this->workspace => $this->groups];
    }

    public function workspaceOfGroup(string $group): ?string
    {
        foreach ($this->workspacesWithOwnerGroups() as $workspace => $groups) {
            if (in_array($group, $groups, true)) {
                return $workspace;
            }
        }

        return null;
    }

    /**
     * "jana" kommt in die erste Gruppe, "jana:Sport" in die Gruppe Sport.
     *
     * @param list<string> $entries
     * @param list<string> $groups
     * @return array<string, string>
     */
    private static function parseEditors(array $entries, array $groups): array
    {
        $editors = [];
        foreach ($entries as $entry) {
            $parts = array_map(trim(...), explode(':', $entry, 2));
            $editors[$parts[0]] = ($parts[1] ?? '') !== '' ? $parts[1] : (string)($groups[0] ?? '');
        }

        return $editors;
    }

    private function validate(?string $explicitWorkspace): void
    {
        foreach ($this->groups as $group) {
            if (mb_strlen($group) > self::MAX_GROUP_TITLE_LENGTH) {
                throw new InvalidSetupOptions(sprintf(
                    'Der Gruppenname "%s" ist laenger als %d Zeichen, mehr fasst ein TYPO3-Gruppentitel nicht.',
                    $group,
                    self::MAX_GROUP_TITLE_LENGTH,
                ));
            }
        }
        if (($this->attachTo !== [] || $this->attachToEditors) && $this->scenario !== SetupScenario::ExistingGroups) {
            throw new InvalidSetupOptions('--attach-to gilt nur fuer das Szenario "existing-groups".');
        }

        foreach ($this->editors as $username => $group) {
            if ($this->scenario === SetupScenario::ExistingGroups) {
                throw new InvalidSetupOptions('Im bestehenden Gruppenbaum haben Redakteure schon ihre Gruppen; --editor gilt hier nicht.');
            }
            if ($username === '' || !in_array($group, $this->groups, true)) {
                throw new InvalidSetupOptions(sprintf(
                    '--editor="%s:%s" nennt eine Gruppe, die dieser Lauf nicht einrichtet. Moeglich: %s.',
                    $username,
                    $group,
                    implode(', ', $this->groups),
                ));
            }
        }

        match ($this->scenario) {
            SetupScenario::Fresh, SetupScenario::SingleGroup => count($this->groups) === 1 || throw new InvalidSetupOptions(sprintf(
                'Das Szenario "%s" richtet genau eine Gruppe ein, angegeben waren %d. Fuer einen Workspace je Gruppe: "group-per-workspace".',
                $this->scenario->value,
                count($this->groups),
            )),
            SetupScenario::GroupPerWorkspace => $this->validateGroupPerWorkspace($explicitWorkspace),
            SetupScenario::ExistingGroups => $this->validateExistingGroups(),
        };
    }

    private function validateGroupPerWorkspace(?string $explicitWorkspace): bool
    {
        if ($this->groups === []) {
            throw new InvalidSetupOptions('Bitte die Gruppen nennen, zum Beispiel: --group="News,Sport,Kultur".');
        }
        if ($explicitWorkspace !== null) {
            throw new InvalidSetupOptions('Hier bekommt jede Gruppe einen Workspace mit ihrem Namen, --workspace gilt nicht.');
        }
        if (in_array(SetupPlanner::SHARED_GROUP, $this->groups, true)) {
            throw new InvalidSetupOptions(sprintf('"%s" ist die Gruppe, ueber die sich die Gruppen die Seiten teilen. Bitte einen anderen Namen waehlen.', SetupPlanner::SHARED_GROUP));
        }

        return true;
    }

    private function validateExistingGroups(): bool
    {
        if ($this->groups !== []) {
            throw new InvalidSetupOptions('Im bestehenden Gruppenbaum legt das Setup keine Gruppen an. Die Gruppen nennt --attach-to.');
        }
        if ($this->attachTo === [] && !$this->attachToEditors) {
            throw new InvalidSetupOptions(
                'Welche Gruppen sollen veroeffentlichen duerfen? --attach-to="Redaktion,Online-Desk" (Titel oder uid), '
                . 'oder --attach-to-editors fuer jede Gruppe mit Seitenmodul.',
            );
        }

        return true;
    }
}
