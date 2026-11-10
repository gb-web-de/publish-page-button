<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Liest und schreibt die Core-Datensaetze, aus denen eine Redaktion besteht: Backend-Gruppen, Benutzer,
 * Seiten und Workspaces.
 *
 * Geschrieben wird ueber den DataHandler, das Ergebnis sieht also aus wie von Hand geklickt - mit einer
 * Ausnahme, den Seitenrechten, die bewusst ein Massen-Update sind (siehe grantUnownedPages()).
 *
 * Jeder Schreibzugriff hier FUEGT HINZU. Nichts nimmt einer Installation ein Modul, einen Besitzer, eine
 * Untergruppe oder ein Seitenrecht weg: Setup laeuft gegen Live-Installationen, und ein Redakteur, der
 * seinen Zugriff verliert, weil ein Befehl seine Gruppe "aufgeraeumt" hat, ist genau das, was diese Klasse
 * ausschliessen soll. Die einzigen Ausnahmen sind die beiden ausdruecklich angeforderten Reparaturen von
 * publishpage:check (disallowLiveEditing(), allowPublishingOutsidePublishStage()).
 */
class InstallationRepository
{
    /** Was eine vom Setup angelegte Gruppe bearbeiten darf. */
    public const string EDITOR_TABLES = 'pages,tt_content,sys_file_reference,sys_category';

    /**
     * Seitentypen, die eine neue Gruppe anlegen und bearbeiten darf: Standard, Link, Verweis, Ordner,
     * Trenner. Ohne diese Liste lehnt der DataHandler schon die erste Workspace-Version einer Seite ab.
     */
    public const string EDITOR_PAGE_TYPES = '1,3,4,199,254';

    /** Anzeigen, bearbeiten, loeschen, Unterseite anlegen, Inhalte bearbeiten - alles ausser Seitenrechten. */
    public const int EDITOR_PAGE_PERMISSIONS = Permission::ALL;

    /** sys_workspace.publish_access Bit 1: nur aus der Publish-Stage veroeffentlichen. */
    public const int PUBLISH_ONLY_FROM_PUBLISH_STAGE = 1;

    /** workspace_perms Bit 1: Live bearbeiten. */
    public const int EDIT_LIVE = 1;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function countPages(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<int>
     */
    public function findRootPageUids(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $uids = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // Nur Live-Seiten: Auf den Platzhalter einer neuen Startseite im Workspace kann niemand gemountet werden.
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(intval(...), $uids);
    }

    public function createRootPage(string $title): int
    {
        return $this->insert('pages', [
            'pid' => 0,
            'title' => $title,
            'doktype' => 1,
            'is_siteroot' => 1,
            'hidden' => 0,
        ]);
    }

    /**
     * Eine Gruppe nach Titel oder - wenn der Verweis nur aus Ziffern besteht - nach uid, damit
     * "--attach-to=12" auch in einem Baum klappt, in dem mehrere Gruppen gleich heissen.
     *
     * @return array<string, mixed>|null
     */
    public function findGroup(string $reference): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $queryBuilder->getRestrictions()->removeAll();

        $field = ctype_digit($reference) ? 'uid' : 'title';
        $value = $field === 'uid'
            ? $queryBuilder->createNamedParameter((int)$reference, Connection::PARAM_INT)
            : $queryBuilder->createNamedParameter($reference);

        $row = $queryBuilder
            ->select('*')
            ->from('be_groups')
            ->where(
                $queryBuilder->expr()->eq($field, $value),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Gruppen, deren EIGENE Modulliste $module enthaelt.
     *
     * @return list<array<string, mixed>>
     */
    public function findGroupsWithModule(string $module): array
    {
        // In PHP gefiltert statt mit inSet(): SQLite nimmt dort keine Platzhalter, und die Gruppentabelle ist klein.
        return array_values(array_filter(
            $this->findAllGroups(),
            static fn (array $group): bool => in_array($module, CommaList::parse((string)$group['groupMods']), true),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllGroups(): array
    {
        return $this->findAllRows('be_groups');
    }

    /**
     * @param list<int> $mounts
     */
    public function createEditorGroup(string $title, string $modules, array $mounts): int
    {
        return $this->insert('be_groups', [
            'pid' => 0,
            'title' => $title,
            'description' => 'Angelegt von publishpage:setup.',
            'groupMods' => $modules,
            'tables_select' => self::EDITOR_TABLES,
            'tables_modify' => self::EDITOR_TABLES,
            'db_mountpoints' => implode(',', $mounts),
            'pagetypes_select' => self::EDITOR_PAGE_TYPES,
            'explicit_allowdeny' => implode(',', array_map(
                static fn (string $type): string => 'tt_content:CType:' . $type,
                $this->contentTypes(),
            )),
            // Nicht "Live bearbeiten": Sonst entsteht keine Workspace-Version, und der Knopf haette nichts
            // zu veroeffentlichen. 0 ist ohnehin der Standard der Gruppe - steht trotzdem hier, weil das
            // Benutzerformular 1 vorgibt und beide Werte ODER-verknuepft werden.
            'workspace_perms' => 0,
        ]);
    }

    /**
     * Alle Inhaltstypen, die diese Installation kennt. CType ist "explicitAllow": Ohne Eintrag darf eine
     * Gruppe nicht einmal ein Textelement anlegen.
     *
     * @return list<string>
     */
    public function contentTypes(): array
    {
        $types = [];
        foreach ($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? [] as $item) {
            $value = (string)($item['value'] ?? '');
            if ($value !== '' && $value !== '--div--') {
                $types[] = $value;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Eine Gruppe, die alles von $subgroupUid erbt und selbst nichts mitbringt.
     */
    public function createInheritingGroup(string $title, int $subgroupUid): int
    {
        return $this->insert('be_groups', [
            'pid' => 0,
            'title' => $title,
            'description' => 'Angelegt von publishpage:setup. Seiten und Rechte kommen aus der Untergruppe, der Workspace gehoert dieser Gruppe.',
            'subgroup' => (string)$subgroupUid,
            'workspace_perms' => 0,
        ]);
    }

    /**
     * @param list<string> $modules
     */
    public function addModulesToGroup(int $groupUid, string $currentModules, array $modules): void
    {
        $this->update('be_groups', $groupUid, ['groupMods' => CommaList::merge($currentModules, $modules)]);
    }

    /**
     * Seiten, die noch keiner Gruppe gehoeren, gehen an $groupUid.
     *
     * Nur die: Eine Seite, die schon einer Gruppe gehoert, ist Teil eines Rechtekonzepts, und sie zu
     * uebernehmen wuerde diese Gruppe aussperren. In einer frischen Installation sind das alle Seiten.
     *
     * Ein schlichtes UPDATE statt DataHandler: Ein Baum mit zehntausenden Seiten waeren sonst ebenso viele
     * DataHandler-Laeufe, und Seitenrechte werden weder versioniert noch protokolliert.
     *
     * @return int wie viele Seiten uebergeben wurden
     */
    public function grantUnownedPages(int $groupUid): int
    {
        if ($groupUid < 1) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->update('pages')
            ->set('perms_groupid', $groupUid, true, Connection::PARAM_INT)
            ->set('perms_group', self::EDITOR_PAGE_PERMISSIONS, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('perms_groupid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    public function countPagesOwnedByOtherGroups(int $groupUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->gt('perms_groupid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('perms_groupid', $queryBuilder->createNamedParameter($groupUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUser(string $username): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Aktive Benutzer ohne Admin-Rechte, ausser dem CLI-Benutzer.
     *
     * @return list<array<string, mixed>>
     */
    public function findEditors(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'username', 'usergroup', 'workspace_perms')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('username', $queryBuilder->createNamedParameter('_cli_')),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Ein neuer Redakteur, der vom ersten Login an im Workspace $workspaceUid arbeitet.
     *
     * Das Passwort geht durch den DataHandler, der es hasht und die Passwort-Richtlinie anwendet. Lehnt
     * die Richtlinie es ab, entsteht kein Benutzer, und zurueck kommt 0.
     */
    public function createEditor(string $username, string $password, int $groupUid, int $workspaceUid): int
    {
        $uid = $this->insert('be_users', [
            'pid' => 0,
            'username' => $username,
            'password' => $password,
            'usergroup' => (string)$groupUid,
            // Das TCA gibt "Live bearbeiten" = 1 vor. Damit landete jede Aenderung sofort live, und der Knopf
            // haette nie etwas zu tun.
            'workspace_perms' => 0,
            'admin' => 0,
            // Ohne das kommt ein per DataHandler angelegter Benutzer deaktiviert heraus, und das einzige
            // Symptom ist ein Login-Formular, das "falsches Passwort" sagt.
            'disable' => 0,
        ]);

        // workspace_id hat kein TCA, der DataHandler verwirft es. Ohne den Wert waehlte der Core beim Login
        // den ersten erlaubten Workspace; so ist es der, den das Setup meint.
        if ($uid > 0 && $workspaceUid > 0) {
            $this->connectionPool->getConnectionForTable('be_users')
                ->update('be_users', ['workspace_id' => $workspaceUid], ['uid' => $uid]);
        }

        return $uid;
    }

    public function addUserToGroup(int $userUid, string $currentGroups, int $groupUid): void
    {
        $this->update('be_users', $userUid, ['usergroup' => CommaList::merge($currentGroups, [(string)$groupUid])]);
    }

    public function disallowLiveEditing(int $userUid, int $currentPermissions): void
    {
        $this->update('be_users', $userUid, ['workspace_perms' => $currentPermissions & ~self::EDIT_LIVE]);
    }

    public function disallowLiveEditingForGroup(int $groupUid, int $currentPermissions): void
    {
        $this->update('be_groups', $groupUid, ['workspace_perms' => $currentPermissions & ~self::EDIT_LIVE]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWorkspaceByTitle(string $title): ?array
    {
        foreach ($this->findAllWorkspaces() as $workspace) {
            if ((string)$workspace['title'] === $title) {
                return $workspace;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllWorkspaces(): array
    {
        return $this->findAllRows('sys_workspace');
    }

    /**
     * Ein Workspace, in dem Besitzer jederzeit veroeffentlichen duerfen und niemand Live bearbeitet.
     */
    public function createWorkspace(string $title, string $description): int
    {
        return $this->insert('sys_workspace', [
            'pid' => 0,
            'title' => $title,
            'description' => $description,
            // Nicht "nur aus der Publish-Stage": Dort lehnt der Knopf ab.
            'publish_access' => 0,
            'live_edit' => 0,
        ]);
    }

    /**
     * Fuegt Besitzer hinzu und behaelt die, die schon da sind.
     *
     * Besitzer sind ein Core-Gruppenfeld ueber be_users UND be_groups (`be_users_3,be_groups_7`). Eine
     * Gruppe als Besitzer heisst: Jeder, der spaeter in diese Gruppe kommt, darf auch veroeffentlichen,
     * ohne dass jemand diesen Datensatz noch einmal anfasst.
     *
     * @param list<string> $owners Verweise wie be_groups_7
     */
    public function addWorkspaceOwners(int $workspaceUid, array $owners): void
    {
        $row = $this->findRow('sys_workspace', $workspaceUid);
        if ($row === null || CommaList::containsAll((string)$row['adminusers'], $owners)) {
            return;
        }

        $this->update('sys_workspace', $workspaceUid, ['adminusers' => CommaList::merge((string)$row['adminusers'], $owners)]);
    }

    public function allowPublishingOutsidePublishStage(int $workspaceUid, int $currentPublishAccess): void
    {
        $this->update('sys_workspace', $workspaceUid, ['publish_access' => $currentPublishAccess & ~self::PUBLISH_ONLY_FROM_PUBLISH_STAGE]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findAllRows(string $table): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function insert(string $table, array $values): int
    {
        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$placeholder => $values]], []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function update(string $table, int $uid, array $values): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $values]], []);
        $dataHandler->process_datamap();
    }
}
