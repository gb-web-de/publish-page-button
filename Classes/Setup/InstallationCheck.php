<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

/**
 * Was `publishpage:check` ansieht: die Einstellungen, bei denen der Knopf fehlt oder "Keine Berechtigung"
 * sagt, ohne dass irgendwo ein Fehler steht.
 *
 * Der Check selbst liest nur. Die beiden fix-Methoden reparieren je genau eine benannte Sache, auf Wunsch.
 */
class InstallationCheck
{
    public function __construct(
        private readonly InstallationRepository $installation,
    ) {
    }

    /**
     * @param array<string, mixed> $workspace
     */
    public static function publishesOnlyFromPublishStage(array $workspace): bool
    {
        return ((int)($workspace['publish_access'] ?? 0) & InstallationRepository::PUBLISH_ONLY_FROM_PUBLISH_STAGE) !== 0;
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [];

        $pages = $this->installation->countPages();
        $findings[] = $pages === 0
            ? new Finding(Finding::ERROR, 'no-pages', 'Es gibt noch keine Seiten.', 'publishpage:setup fresh legt Startseite und Site an.')
            : new Finding(Finding::OK, 'pages', sprintf('%d Seite(n).', $pages));

        $workspaces = $this->installation->findAllWorkspaces();
        if ($workspaces === []) {
            $findings[] = new Finding(
                Finding::ERROR,
                'no-workspace',
                'Es gibt keinen Workspace. Der Knopf erscheint nur, wenn ein Redakteur in einem Workspace arbeitet.',
                'publishpage:setup single-group --group="Ihre Redaktion" legt einen an.',
            );
        }
        foreach ($workspaces as $workspace) {
            $findings[] = $this->checkWorkspace($workspace);
        }

        return [...$findings, ...$this->checkEditors($workspaces)];
    }

    /**
     * Schaltet "nur aus der Publish-Stage veroeffentlichen" ab - die eine Einstellung, mit der der Knopf
     * nie funktioniert.
     *
     * @return list<string> Titel der geaenderten Workspaces
     */
    public function fixPublishAccess(): array
    {
        $changed = [];
        foreach ($this->installation->findAllWorkspaces() as $workspace) {
            if (self::publishesOnlyFromPublishStage($workspace)) {
                $this->installation->allowPublishingOutsidePublishStage((int)$workspace['uid'], (int)$workspace['publish_access']);
                $changed[] = (string)$workspace['title'];
            }
        }

        return $changed;
    }

    /**
     * Nimmt Redakteuren mit Seitenmodul - und ihren Gruppen - "Live bearbeiten" weg.
     *
     * Eigene Option statt Teil von --fix: Ob jemand Live bearbeiten darf, ist eine redaktionelle
     * Entscheidung, keine kaputte Einstellung. Fuer den Knopf ist es aber der Schalter, an dem alles haengt:
     * Wer Live bearbeiten darf, landet beim Login in Live, und dort gibt es nichts zu veroeffentlichen. Der
     * Benutzer- und der Gruppenwert werden ODER-verknuepft, also muessen beide weg.
     *
     * @return list<string> die geaenderten Benutzer und Gruppen
     */
    public function disallowLiveEditingForEditors(): array
    {
        $changed = [];
        foreach ($this->editorsWhoMayEditLive() as $user) {
            $this->installation->disallowLiveEditing((int)$user['uid'], (int)$user['workspace_perms']);
            $changed[] = (string)$user['username'];
        }
        foreach ($this->editorGroupsWhoMayEditLive() as $group) {
            $this->installation->disallowLiveEditingForGroup((int)$group['uid'], (int)$group['workspace_perms']);
            $changed[] = sprintf('Gruppe "%s"', $group['title']);
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $workspace
     */
    private function checkWorkspace(array $workspace): Finding
    {
        $title = sprintf('Workspace "%s" (uid %d)', $workspace['title'], $workspace['uid']);

        if (self::publishesOnlyFromPublishStage($workspace)) {
            return new Finding(
                Finding::ERROR,
                'publish-stage-only',
                sprintf('%s erlaubt Veroeffentlichen nur aus der Publish-Stage. Dort lehnt der Knopf immer ab.', $title),
                'publishpage:check --fix schaltet das ab.',
            );
        }
        if (trim((string)$workspace['adminusers']) === '') {
            return new Finding(
                Finding::WARNING,
                'no-owner',
                sprintf('%s hat keine Besitzer. Ohne Live-Zugriff duerfen dort nur Admins veroeffentlichen.', $title),
                'publishpage:setup existing-groups --workspace="' . $workspace['title'] . '" --attach-to=<Gruppe> macht Gruppen zu Besitzern.',
            );
        }

        return new Finding(Finding::OK, 'workspace', sprintf('%s: Besitzer duerfen veroeffentlichen.', $title));
    }

    /**
     * @param list<array<string, mixed>> $workspaces
     * @return list<Finding>
     */
    private function checkEditors(array $workspaces): array
    {
        $groups = $this->groupsByUid();
        $owners = [];
        foreach ($workspaces as $workspace) {
            $owners = [...$owners, ...CommaList::parse((string)$workspace['adminusers'])];
        }

        $editors = array_filter($this->installation->findEditors(), fn (array $user): bool => $this->hasPageModule($user, $groups));
        if ($editors === []) {
            return [new Finding(
                Finding::WARNING,
                'no-editors',
                'Kein aktiver Redakteur hat das Seitenmodul. Den Knopf sehen dann nur Admins.',
                'publishpage:setup ... --editor=<name> legt einen an.',
            )];
        }

        $publishers = [];
        $cannotPublish = [];
        foreach ($editors as $user) {
            if ($this->ownsAWorkspace($user, $groups, $owners)) {
                $publishers[] = (string)$user['username'];
            } else {
                $cannotPublish[] = (string)$user['username'];
            }
        }

        $findings = [];
        if ($publishers !== []) {
            $findings[] = new Finding(Finding::OK, 'publishers', sprintf('%d Redakteur(e) duerfen per Knopf veroeffentlichen: %s.', count($publishers), $this->shortList($publishers)));
        }
        if ($cannotPublish !== []) {
            $findings[] = new Finding(
                Finding::WARNING,
                'cannot-publish',
                sprintf('%d Redakteur(e) haben das Seitenmodul, besitzen aber keinen Workspace - der Knopf sagt ihnen "Keine Berechtigung": %s.', count($cannotPublish), $this->shortList($cannotPublish)),
                'publishpage:setup existing-groups --attach-to=<ihre Gruppe> macht die Gruppe zum Workspace-Besitzer.',
            );
        }

        $liveEditors = array_map(static fn (array $user): string => (string)$user['username'], $this->editorsWhoMayEditLive());
        if ($liveEditors !== []) {
            $findings[] = new Finding(
                Finding::WARNING,
                'users-edit-live',
                sprintf('%d Redakteur(e) duerfen Live bearbeiten. Was sie dort aendern, ist sofort online, am Knopf vorbei: %s.', count($liveEditors), $this->shortList($liveEditors)),
                'Das Benutzerformular hakt "Live bearbeiten" standardmaessig an. publishpage:check --fix-live-editing nimmt es allen Redakteuren mit Seitenmodul.',
            );
        }
        foreach ($this->editorGroupsWhoMayEditLive() as $group) {
            $findings[] = new Finding(
                Finding::WARNING,
                'group-edits-live',
                sprintf('Gruppe "%s" darf Live bearbeiten. Ihre Redakteure landen in Live und sehen den Knopf nicht.', $group['title']),
                'publishpage:check --fix-live-editing nimmt es der Gruppe und ihren Redakteuren.',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function editorsWhoMayEditLive(): array
    {
        $groups = $this->groupsByUid();

        return array_values(array_filter(
            $this->installation->findEditors(),
            fn (array $user): bool => ((int)$user['workspace_perms'] & InstallationRepository::EDIT_LIVE) !== 0 && $this->hasPageModule($user, $groups),
        ));
    }

    /**
     * Gruppen mit Seitenmodul (auch geerbt), die selbst "Live bearbeiten" haben.
     *
     * @return list<array<string, mixed>>
     */
    private function editorGroupsWhoMayEditLive(): array
    {
        $groups = $this->groupsByUid();

        return array_values(array_filter(
            $groups,
            fn (array $group): bool => ((int)$group['workspace_perms'] & InstallationRepository::EDIT_LIVE) !== 0
                && in_array(SetupPlanner::PAGE_MODULE, $this->effectiveModules((int)$group['uid'], $groups), true),
        ));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $groups
     */
    private function hasPageModule(array $user, array $groups): bool
    {
        foreach (CommaList::parse((string)$user['usergroup']) as $groupUid) {
            if (in_array(SetupPlanner::PAGE_MODULE, $this->effectiveModules((int)$groupUid, $groups), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Besitz zaehlt ueber den Benutzer selbst, seine Gruppen und deren Untergruppen - so loest auch der
     * Core die Gruppen eines Benutzers auf.
     *
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $groups
     * @param list<string> $owners
     */
    private function ownsAWorkspace(array $user, array $groups, array $owners): bool
    {
        if (in_array('be_users_' . $user['uid'], $owners, true)) {
            return true;
        }
        foreach (CommaList::parse((string)$user['usergroup']) as $groupUid) {
            foreach ($this->selfAndSubgroups((int)$groupUid, $groups) as $candidate) {
                if (in_array('be_groups_' . $candidate, $owners, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return list<string>
     */
    private function effectiveModules(int $uid, array $groups): array
    {
        $modules = [];
        foreach ($this->selfAndSubgroups($uid, $groups) as $candidate) {
            $modules = [...$modules, ...CommaList::parse((string)$groups[$candidate]['groupMods'])];
        }

        return array_values(array_unique($modules));
    }

    /**
     * Die Gruppe und alle Untergruppen, die sie einschliesst, transitiv - und gegen Zyklen geschuetzt.
     *
     * @param array<int, array<string, mixed>> $groups
     * @return list<int>
     */
    private function selfAndSubgroups(int $uid, array $groups): array
    {
        $seen = [];
        $queue = [$uid];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current]) || !isset($groups[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach (CommaList::parse((string)$groups[$current]['subgroup']) as $subgroup) {
                $queue[] = (int)$subgroup;
            }
        }

        return array_keys($seen);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupsByUid(): array
    {
        $groups = [];
        foreach ($this->installation->findAllGroups() as $group) {
            $groups[(int)$group['uid']] = $group;
        }

        return $groups;
    }

    /**
     * @param list<string> $names
     */
    private function shortList(array $names): string
    {
        return implode(', ', array_slice($names, 0, 10)) . (count($names) > 10 ? ', ...' : '');
    }
}
