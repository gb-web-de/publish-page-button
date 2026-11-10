<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * Macht aus SetupOptions die Liste der Schritte, die `publishpage:setup` zeigt und dann ausfuehrt.
 *
 * Nachgesehen wird schon beim Planen: Der Plan - und `--dry-run` - sagt "anlegen" nur fuer das, was
 * wirklich fehlt, und "bleibt" fuer das, was da ist. Zweimal laufen lassen ist deshalb harmlos; der zweite
 * Plan besteht nur aus "bleibt"-Zeilen.
 *
 * Das Rechtemodell des Knopfs: Redakteure bearbeiten nie Live (workspace_perms 0), sondern nur im
 * Workspace. Veroeffentlichen duerfen dort ohne Live-Zugriff nur Besitzer (WorkspacePublishGate) - die
 * Redaktionsgruppen werden also Besitzer ihres Workspaces, nicht nur Mitglieder.
 */
class SetupPlanner
{
    /** Das Seitenmodul: Dort sitzt der Knopf, und daran erkennt --attach-to-editors die Redaktionsgruppen. */
    public const string PAGE_MODULE = 'web_layout';

    /** Was eine neu angelegte Gruppe bekommt: Seitenmodul, Visual Editor, Liste und Medien. */
    public const array EDITOR_MODULES = [self::PAGE_MODULE, 'web_edit', 'records', 'media_management'];

    /**
     * group-per-workspace: traegt Seiten und Bearbeitungsrechte, die sich alle Workspace-Gruppen teilen.
     * Sie selbst besitzt keinen Workspace; siehe plan().
     */
    public const string SHARED_GROUP = 'Redaktion (gemeinsame Rechte)';

    public const string ROOT_PAGE_TITLE = 'Startseite';
    public const string SITE_IDENTIFIER = 'main';

    public function __construct(
        private readonly InstallationRepository $installation,
        private readonly SiteWriter $siteWriter,
    ) {
    }

    /**
     * @return list<SetupStep>
     * @throws InvalidSetupOptions wenn etwas, das die Optionen nennen, nicht existiert
     */
    public function plan(SetupOptions $options): array
    {
        if ($options->scenario === SetupScenario::ExistingGroups) {
            $groups = $this->resolveAttachTo($options);

            return [
                ...array_map(
                    static fn (array $group): SetupStep => SetupStep::unchanged(sprintf('Gruppe "%s" (uid %d) bleibt, wie sie ist.', $group['title'], $group['uid'])),
                    array_values($groups),
                ),
                $this->planWorkspace(
                    (string)$options->workspace,
                    array_map(static fn (int $uid): string => 'be_groups_' . $uid, array_keys($groups)),
                    implode('", "', array_map(static fn (array $group): string => (string)$group['title'], $groups)),
                ),
            ];
        }

        $steps = $options->scenario === SetupScenario::Fresh ? $this->planPageTree($options->siteBase) : [];

        // Ein Workspace je Gruppe: Die Gruppen duerfen einander nicht einschliessen - Besitz wandert mit der
        // Untergruppe, und Sport saesse sonst im Workspace von News. Eine Seite gehoert aber nur einer Gruppe.
        // Die gemeinsame Gruppe ohne Workspace traegt deshalb Seiten und Rechte, jede Workspace-Gruppe
        // schliesst sie ein und bringt selbst nur den Besitz ihres Workspaces mit.
        $perWorkspace = $options->scenario === SetupScenario::GroupPerWorkspace;
        if ($perWorkspace) {
            $steps[] = $this->planEditorGroup(self::SHARED_GROUP);
        }
        foreach ($options->groups as $group) {
            $steps[] = $this->planEditorGroup($group, $perWorkspace ? self::SHARED_GROUP : null);
        }
        foreach ($options->workspacesWithOwnerGroups() as $workspace => $ownerGroups) {
            $steps[] = $this->planWorkspace(
                $workspace,
                static fn (SetupContext $context): array => array_map(
                    static fn (string $title): string => 'be_groups_' . $context->group($title),
                    $ownerGroups,
                ),
                implode('", "', $ownerGroups),
                $this->knownGroupReferences($ownerGroups),
            );
        }
        foreach ($options->editors as $username => $group) {
            $steps[] = $this->planEditor($username, $group, $options);
        }

        return $steps;
    }

    /**
     * @return list<SetupStep>
     */
    private function planPageTree(string $siteBase): array
    {
        $pageCount = $this->installation->countPages();
        if ($pageCount > 0) {
            return [SetupStep::unchanged(sprintf('Seitenbaum bleibt (%d Seiten).', $pageCount))];
        }

        return [new SetupStep(
            sprintf('Startseite "%s" und Site "%s" mit Basis "%s" anlegen.', self::ROOT_PAGE_TITLE, self::SITE_IDENTIFIER, $siteBase),
            function (SetupContext $context) use ($siteBase): void {
                $rootUid = $this->installation->createRootPage(self::ROOT_PAGE_TITLE);
                if ($rootUid < 1) {
                    throw new \RuntimeException('Die Startseite liess sich nicht anlegen.', 1762290001);
                }
                $this->siteWriter->createNewBasicSite(self::SITE_IDENTIFIER, $rootUid, $siteBase);
                $context->report(sprintf('Startseite uid %d, Site "%s".', $rootUid, self::SITE_IDENTIFIER));
            },
        )];
    }

    /**
     * @param string|null $inheritFrom Gruppe, die Seiten und Rechte traegt; die neue Gruppe schliesst sie nur ein
     */
    private function planEditorGroup(string $title, ?string $inheritFrom = null): SetupStep
    {
        $existing = $this->installation->findGroup($title);
        if ($existing !== null) {
            return $this->keepExistingGroup($existing, $inheritFrom === null && $title !== self::SHARED_GROUP);
        }

        if ($inheritFrom !== null) {
            return new SetupStep(
                sprintf('Gruppe "%s" anlegen, die Seiten und Rechte von "%s" erbt.', $title, $inheritFrom),
                function (SetupContext $context) use ($title, $inheritFrom): void {
                    $uid = $this->installation->createInheritingGroup($title, $context->group($inheritFrom));
                    if ($uid < 1 || $context->group($inheritFrom) < 1) {
                        throw new \RuntimeException(sprintf('Die Gruppe "%s" liess sich nicht anlegen.', $title), 1762290002);
                    }
                    $context->rememberGroup($title, $uid);
                    $context->report(sprintf('Gruppe "%s" ist uid %d.', $title, $uid));
                },
            );
        }

        return new SetupStep(
            sprintf('Redaktionsgruppe "%s" anlegen: Seitenmodul, Visual Editor, Liste und Medien, alle Inhaltstypen, auf den Startseiten gemountet, bearbeitet nur im Workspace.', $title),
            function (SetupContext $context) use ($title): void {
                $uid = $this->installation->createEditorGroup($title, implode(',', self::EDITOR_MODULES), $this->installation->findRootPageUids());
                if ($uid < 1) {
                    throw new \RuntimeException(sprintf('Die Gruppe "%s" liess sich nicht anlegen.', $title), 1762290003);
                }
                $context->rememberGroup($title, $uid);

                $granted = $this->installation->grantUnownedPages($uid);
                $ownedByOthers = $this->installation->countPagesOwnedByOtherGroups($uid);
                $context->report(sprintf('Gruppe "%s" ist uid %d und besitzt %d Seite(n).', $title, $uid, $granted));
                if ($ownedByOthers > 0) {
                    $context->report(sprintf(
                        '%d Seite(n) gehoeren schon einer anderen Gruppe und blieben unberuehrt. Wenn "%s" sie bearbeiten soll: Zugriff > Seitenrechte.',
                        $ownedByOthers,
                        $title,
                    ));
                }
            },
        );
    }

    /**
     * Eine bestehende Gruppe bekommt hoechstens das Seitenmodul dazu, denn dort sitzt der Knopf. Alles
     * andere - Seiten, Tabellen, Mounts - ist Sache der Installation und bleibt.
     *
     * @param array<string, mixed> $group
     */
    private function keepExistingGroup(array $group, bool $needsPageModule): SetupStep
    {
        $uid = (int)$group['uid'];
        $title = (string)$group['title'];
        $modules = (string)$group['groupMods'];
        $remember = static function (SetupContext $context) use ($title, $uid): void {
            $context->rememberGroup($title, $uid);
        };

        if (!$needsPageModule || CommaList::containsAll($modules, [self::PAGE_MODULE])) {
            return new SetupStep(sprintf('Gruppe "%s" (uid %d) bleibt, wie sie ist.', $title, $uid), $remember, false);
        }

        return new SetupStep(
            sprintf('Bestehender Gruppe "%s" (uid %d) das Seitenmodul geben. Ihre anderen Rechte bleiben.', $title, $uid),
            function (SetupContext $context) use ($uid, $modules, $remember): void {
                $this->installation->addModulesToGroup($uid, $modules, [self::PAGE_MODULE]);
                $remember($context);
            },
        );
    }

    /**
     * Die Verweise fuer Gruppen, die es schon gibt - oder null, sobald eine erst angelegt wird: Deren uid
     * kennt der Plan noch nicht, also kann er fuer sie auch nicht "nichts zu tun" sagen.
     *
     * @param list<string> $titles
     * @return list<string>|null
     */
    private function knownGroupReferences(array $titles): ?array
    {
        $references = [];
        foreach ($titles as $title) {
            $group = $this->installation->findGroup($title);
            if ($group === null) {
                return null;
            }
            $references[] = 'be_groups_' . (int)$group['uid'];
        }

        return $references;
    }

    /**
     * @param list<string>|\Closure(SetupContext): list<string> $owners Verweise wie be_groups_7
     * @param list<string>|null $knownOwners was $owners beim Planen schon ergibt, falls bekannt
     */
    private function planWorkspace(string $title, array|\Closure $owners, string $ownerLabel, ?array $knownOwners = null): SetupStep
    {
        $existing = $this->installation->findWorkspaceByTitle($title);
        $knownOwners ??= is_array($owners) ? $owners : null;

        $description = $existing !== null
            ? sprintf('Workspace "%s" (uid %d) bleibt', $title, $existing['uid'])
            : sprintf('Workspace "%s" anlegen', $title);
        $description .= sprintf('; Besitzer (duerfen veroeffentlichen): "%s".', $ownerLabel);
        if ($existing !== null && InstallationCheck::publishesOnlyFromPublishStage($existing)) {
            $description .= ' Achtung: Er erlaubt Veroeffentlichen nur aus der Publish-Stage, dort lehnt der Knopf ab - siehe publishpage:check --fix.';
        }

        $alreadyOwned = $existing !== null && $knownOwners !== null
            && CommaList::containsAll((string)$existing['adminusers'], $knownOwners);

        return new SetupStep(
            $description,
            function (SetupContext $context) use ($title, $owners, $existing): void {
                $workspaceUid = $existing !== null
                    ? (int)$existing['uid']
                    : $this->installation->createWorkspace($title, 'Angelegt von publishpage:setup. Aenderungen gehen per "Seite veroeffentlichen" live.');
                if ($workspaceUid < 1) {
                    throw new \RuntimeException(sprintf('Der Workspace "%s" liess sich nicht anlegen.', $title), 1762290004);
                }
                $context->rememberWorkspace($title, $workspaceUid);
                $this->installation->addWorkspaceOwners($workspaceUid, $owners instanceof \Closure ? $owners($context) : $owners);
                $context->report(sprintf('Workspace "%s" ist uid %d.', $title, $workspaceUid));
            },
            !$alreadyOwned,
        );
    }

    private function planEditor(string $username, string $group, SetupOptions $options): SetupStep
    {
        $workspace = (string)$options->workspaceOfGroup($group);
        $existing = $this->installation->findUser($username);

        if ($existing !== null) {
            $groupUid = (int)($this->installation->findGroup($group)['uid'] ?? 0);
            if ($groupUid > 0 && CommaList::containsAll((string)$existing['usergroup'], [(string)$groupUid])) {
                return SetupStep::unchanged(sprintf('Benutzer "%s" bleibt, ist schon in "%s".', $username, $group));
            }

            return new SetupStep(
                sprintf('Bestehenden Benutzer "%s" in die Gruppe "%s" aufnehmen. Passwort und Einstellungen bleiben.', $username, $group),
                function (SetupContext $context) use ($existing, $group): void {
                    $this->installation->addUserToGroup((int)$existing['uid'], (string)$existing['usergroup'], $context->group($group));
                },
            );
        }

        $password = $options->editorPassword;
        if ($password === null) {
            throw new InvalidSetupOptions(sprintf(
                'Fuer den neuen Redakteur "%s" fehlt ein Passwort: --editor-password=... oder die Umgebungsvariable PUBLISHPAGE_EDITOR_PASSWORD.',
                $username,
            ));
        }

        return new SetupStep(
            sprintf('Redakteur "%s" in "%s" anlegen, arbeitet im Workspace "%s" (nicht Live).', $username, $group, $workspace),
            function (SetupContext $context) use ($username, $password, $group, $workspace): void {
                $uid = $this->installation->createEditor($username, $password, $context->group($group), $context->workspace($workspace));
                if ($uid < 1) {
                    throw new \RuntimeException(sprintf(
                        'Der Redakteur "%s" liess sich nicht anlegen. Meist erfuellt das Passwort die Passwort-Richtlinie der Installation nicht.',
                        $username,
                    ), 1762290005);
                }
                $context->report(sprintf('Redakteur "%s" ist uid %d.', $username, $uid));
            },
        );
    }

    /**
     * @return array<int, array<string, mixed>> uid => Gruppe
     */
    private function resolveAttachTo(SetupOptions $options): array
    {
        $rows = [];
        if ($options->attachToEditors) {
            foreach ($this->installation->findGroupsWithModule(self::PAGE_MODULE) as $row) {
                $rows[(int)$row['uid']] = $row;
            }
        }
        foreach ($options->attachTo as $reference) {
            $row = $this->installation->findGroup($reference);
            if ($row === null) {
                throw new InvalidSetupOptions(sprintf(
                    'Es gibt keine Backend-Gruppe "%s". --attach-to nimmt Gruppentitel oder uids; publishpage:check listet die Gruppen.',
                    $reference,
                ));
            }
            $rows[(int)$row['uid']] = $row;
        }

        if ($rows === []) {
            throw new InvalidSetupOptions(sprintf(
                'Keine Gruppe hat das Seitenmodul (%s), --attach-to-editors fand also nichts. Bitte die Gruppen mit --attach-to nennen.',
                self::PAGE_MODULE,
            ));
        }

        return $rows;
    }
}
