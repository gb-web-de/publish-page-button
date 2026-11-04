<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Richtet den Redaktions-Workspace ein: Alle Benutzergruppen sind Besitzer des Workspaces (duerfen also
 * selbst veroeffentlichen), Gruppen und Nicht-Admins verlieren den Live-Zugriff. Laeuft wie
 * luchse:scheduler:setup beim Start des Scheduler-Containers bzw. DDEV-Daemons, also nach jedem Deploy.
 * Neue Gruppen kommen damit automatisch dazu; Live-Zugriff haben nur noch Admins.
 */
#[AsCommand(
    name: 'luchse:workspace:setup',
    description: 'Legt den Redaktions-Workspace an und beschraenkt alle Redakteure darauf (kein Live-Zugriff).'
)]
final class WorkspaceSetupCommand extends Command
{
    public const string TITLE = 'Redaktion';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $groups = $this->connectionPool->getConnectionForTable('be_groups')
            ->select(['uid'], 'be_groups', ['deleted' => 0])
            ->fetchFirstColumn();
        $workspace = [
            'tstamp' => time(),
            'description' => 'Alle Aenderungen der Redakteure landen hier und gehen per "Seite veroeffentlichen" live.',
            // Besitzer duerfen ohne Live-Zugriff veroeffentlichen (WorkspacePublishGate), Mitglieder nicht
            'adminusers' => implode(',', array_map(static fn ($uid): string => 'be_groups_' . $uid, $groups)),
            'publish_access' => 0,
            'live_edit' => 0,
        ];

        $connection = $this->connectionPool->getConnectionForTable('sys_workspace');
        $uid = $connection->select(['uid'], 'sys_workspace', ['title' => self::TITLE, 'deleted' => 0])->fetchOne();
        if ($uid === false) {
            $connection->insert('sys_workspace', $workspace + ['title' => self::TITLE, 'pid' => 0]);
            $output->writeln('Workspace angelegt: ' . self::TITLE);
        } else {
            $connection->update('sys_workspace', $workspace, ['uid' => (int)$uid]);
            $output->writeln('Workspace aktualisiert: ' . self::TITLE);
        }

        // Bit 1 = Live bearbeiten; Gruppen- und Benutzerwert werden ODER-verknuepft, also beide auf 0
        $this->connectionPool->getConnectionForTable('be_groups')->update('be_groups', ['workspace_perms' => 0], ['deleted' => 0]);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $users = $queryBuilder
            ->update('be_users')
            ->set('workspace_perms', 0)
            ->where(
                $queryBuilder->expr()->eq('admin', 0),
                // CLI-Benutzer (_cli_lowlevel) brauchen Live
                $queryBuilder->expr()->notLike('username', $queryBuilder->createNamedParameter('\_cli\_%'))
            )
            ->executeStatement();
        $output->writeln(sprintf('%d Gruppen auf den Workspace beschraenkt, %d Benutzer auf Live-Zugriff 0 gesetzt.', count($groups), $users));
        return Command::SUCCESS;
    }
}
