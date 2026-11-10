<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Command;

use GbWebDe\PublishPageButton\Setup\Finding;
use GbWebDe\PublishPageButton\Setup\InstallationCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * "Warum sagt der Knopf 'Keine Berechtigung'?" als Befehl.
 *
 * Endet mit Fehlercode, wenn er einen Fehler findet - ein Deploy kann ihn also nach publishpage:setup
 * laufen lassen und bei kaputter Konfiguration anhalten, statt es von einem Redakteur zu erfahren.
 */
#[AsCommand(
    name: 'publishpage:check',
    description: 'Prueft, ob Gruppen, Workspaces und Redakteure fuer den Knopf "Seite veroeffentlichen" passen.',
)]
final class CheckInstallationCommand extends Command
{
    private const array ICONS = [
        Finding::OK => '<info>✓</info>',
        Finding::INFO => '<comment>i</comment>',
        Finding::WARNING => '<comment>!</comment>',
        Finding::ERROR => '<error>✗</error>',
    ];

    public function __construct(
        private readonly InstallationCheck $check,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Repariert, was nur eine richtige Antwort hat: "nur aus der Publish-Stage veroeffentlichen" wird abgeschaltet.')
            ->addOption('fix-live-editing', null, InputOption::VALUE_NONE, 'Nimmt allen Redakteuren mit Seitenmodul "Live bearbeiten", damit alles ueber den Workspace geht.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();

        if ($input->getOption('fix')) {
            $changed = $this->check->fixPublishAccess();
            $io->writeln(sprintf('%d Workspace(s) erlauben jetzt Veroeffentlichen per Knopf%s', count($changed), $changed === [] ? '.' : ': ' . implode(', ', $changed) . '.'));
        }
        if ($input->getOption('fix-live-editing')) {
            $changed = $this->check->disallowLiveEditingForEditors();
            $io->writeln($changed === []
                ? 'Kein Redakteur mit Seitenmodul durfte Live bearbeiten.'
                : 'Live bearbeiten abgeschaltet fuer: ' . implode(', ', $changed) . '. Diese Redakteure arbeiten jetzt nur noch im Workspace.');
        }

        $findings = $this->check->run();
        foreach ($findings as $finding) {
            $io->writeln(sprintf('  %s %s', self::ICONS[$finding->severity] ?? ' ', $finding->message));
            if ($finding->hint !== '' && $finding->severity !== Finding::OK) {
                $io->writeln('      → ' . $finding->hint);
            }
        }
        $io->newLine();

        $errors = count(array_filter($findings, static fn (Finding $finding): bool => $finding->severity === Finding::ERROR));
        $warnings = count(array_filter($findings, static fn (Finding $finding): bool => $finding->severity === Finding::WARNING));
        if ($errors > 0) {
            $io->error(sprintf('%d Fehler, %d Warnung(en).', $errors, $warnings));

            return Command::FAILURE;
        }
        if ($warnings > 0) {
            $io->warning(sprintf('%d Warnung(en), keine Fehler.', $warnings));

            return Command::SUCCESS;
        }
        $io->success('Der Knopf ist einsatzbereit.');

        return Command::SUCCESS;
    }
}
