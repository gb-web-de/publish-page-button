<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Command;

use GbWebDe\PublishPageButton\Setup\CommaList;
use GbWebDe\PublishPageButton\Setup\InvalidSetupOptions;
use GbWebDe\PublishPageButton\Setup\SetupContext;
use GbWebDe\PublishPageButton\Setup\SetupOptions;
use GbWebDe\PublishPageButton\Setup\SetupPlanner;
use GbWebDe\PublishPageButton\Setup\SetupScenario;
use GbWebDe\PublishPageButton\Setup\SetupStep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Richtet Redaktionsgruppen, Workspaces und Redakteure so ein, dass der Knopf "Seite veroeffentlichen"
 * funktioniert.
 *
 * Ohne Szenario und mit Terminal fragt ein Assistent; mit Szenario laeuft es unbeaufsichtigt (Deploy, CI).
 * Immer steht erst der Plan da, und `--dry-run` hoert dort auf. Nichts wird weggenommen: Gruppen behalten
 * ihre Module, Workspaces ihre Besitzer, Seiten ihre Gruppe.
 */
#[AsCommand(
    name: 'publishpage:setup',
    description: 'Richtet Redaktionsgruppen, Workspace und Redakteure fuer den Knopf "Seite veroeffentlichen" ein.',
)]
final class SetupCommand extends Command
{
    public const string PASSWORD_VARIABLE = 'PUBLISHPAGE_EDITOR_PASSWORD';

    public function __construct(
        private readonly SetupPlanner $planner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('scenario', InputArgument::OPTIONAL, 'Eins von: ' . implode(', ', SetupScenario::values()) . '. Weglassen, um gefragt zu werden.')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Redaktionsgruppe(n), mit Komma getrennt oder wiederholt. Wird angelegt, wenn sie fehlt.')
            ->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, 'Titel des Workspaces. Standard: der Gruppenname bzw. "' . SetupOptions::DEFAULT_WORKSPACE . '".')
            ->addOption('attach-to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'existing-groups: Gruppen (Titel oder uid), die den Workspace besitzen und veroeffentlichen duerfen.')
            ->addOption('attach-to-editors', null, InputOption::VALUE_NONE, 'existing-groups: jede Gruppe mit Seitenmodul.')
            ->addOption('editor', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Redakteur(e) anlegen oder aufnehmen: "name" (erste Gruppe) oder "name:Gruppe".')
            ->addOption('editor-password', null, InputOption::VALUE_REQUIRED, 'Passwort fuer neue Redakteure. Oder Umgebungsvariable ' . self::PASSWORD_VARIABLE . '.')
            ->addOption('site-base', null, InputOption::VALUE_REQUIRED, 'fresh: Basis-URL der Site fuer einen leeren Seitenbaum.', '/')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur zeigen, was passieren wuerde.')
            ->setHelp(<<<'HELP'
                Beispiele:

                  <info>%command.full_name%</info>
                      Fragt, welche Ausgangslage vorliegt, und dann nach den Namen.

                  <info>%command.full_name% fresh --editor=jana</info>
                      Leere Datenbank: Startseite, Site, Gruppe und Workspace "Redaktion",
                      Redakteurin "jana" (Passwort aus PUBLISHPAGE_EDITOR_PASSWORD).

                  <info>%command.full_name% single-group --group="Online-Redaktion"</info>
                      Eine Gruppe mit eigenem Namen und ein Workspace gleichen Namens.

                  <info>%command.full_name% group-per-workspace --group="News,Sport,Kultur"</info>
                      Drei Gruppen, drei Workspaces gleichen Namens.

                  <info>%command.full_name% existing-groups --attach-to-editors --dry-run</info>
                      Grosser bestehender Gruppenbaum: Gruppen bleiben, wie sie sind, und werden Besitzer
                      des Workspaces "Redaktion". Erst den Plan ansehen.

                Jederzeit wiederholbar - es kommt nur dazu, was fehlt.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();

        try {
            $options = $this->resolveOptions($input, $io);
            $steps = $this->planner->plan($options);
        } catch (InvalidSetupOptions $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->section('Plan: ' . $options->scenario->label());
        foreach ($steps as $step) {
            $io->writeln(sprintf('  %s %s', $step->changesSomething ? '<info>+</info>' : '<comment>=</comment>', $step->description));
        }
        $io->newLine();

        if ($options->dryRun) {
            $io->note('Probelauf - nichts wurde geaendert. Ohne --dry-run ausfuehren, um den Plan anzuwenden.');

            return Command::SUCCESS;
        }
        if (array_filter($steps, static fn (SetupStep $step): bool => $step->changesSomething) === []) {
            $io->success('Alles ist schon eingerichtet.');

            return Command::SUCCESS;
        }
        if ($input->isInteractive() && !$io->confirm('Plan ausfuehren?', true)) {
            $io->writeln('Nichts wurde geaendert.');

            return Command::SUCCESS;
        }

        $context = new SetupContext();
        foreach ($steps as $step) {
            $step->run($context);
        }
        foreach ($context->messages() as $message) {
            $io->writeln('  ' . $message);
        }

        $io->success('Fertig.');
        $lines = [];
        foreach ($context->groups() as $title => $uid) {
            if ($title !== SetupPlanner::SHARED_GROUP) {
                $lines[] = sprintf('Weitere Redakteure fuer "%s": publishpage:setup ... --editor=name:%s  (oder backend:user:create --groups=%d, danach publishpage:check --fix-live-editing)', $title, $title, $uid);
            }
        }
        $lines[] = 'Ergebnis pruefen: publishpage:check';
        $io->section('Weiter');
        $io->listing($lines);

        return Command::SUCCESS;
    }

    private function resolveOptions(InputInterface $input, SymfonyStyle $io): SetupOptions
    {
        $scenarioValue = $input->getArgument('scenario');
        if (!is_string($scenarioValue) || $scenarioValue === '') {
            if (!$input->isInteractive()) {
                throw new InvalidSetupOptions('Bitte ein Szenario nennen: ' . implode(', ', SetupScenario::values()) . '.');
            }

            return $this->askWizard($input, $io);
        }

        $scenario = SetupScenario::tryFrom($scenarioValue)
            ?? throw new InvalidSetupOptions(sprintf('Unbekanntes Szenario "%s". Moeglich: %s.', $scenarioValue, implode(', ', SetupScenario::values())));

        return SetupOptions::fromArray($scenario, $this->optionValues($input));
    }

    /**
     * Der Assistent fragt nur, was das gewaehlte Szenario braucht, mit denselben Vorgaben wie die Optionen,
     * und landet wie ein Skriptaufruf in SetupOptions::fromArray() - es gibt also nur einen Satz Regeln.
     */
    private function askWizard(InputInterface $input, SymfonyStyle $io): SetupOptions
    {
        $io->title('Seite veroeffentlichen - Einrichtung');
        $choices = [];
        foreach (SetupScenario::cases() as $case) {
            $choices[$case->value] = $case->label();
        }
        $scenario = SetupScenario::from((string)$io->choice('Was beschreibt diese Installation?', $choices, SetupScenario::SingleGroup->value));

        $values = $this->optionValues($input);
        if ($scenario === SetupScenario::ExistingGroups) {
            $values['attach-to-editors'] = $io->confirm('Sollen alle Gruppen mit Seitenmodul veroeffentlichen duerfen?', true);
            if (!$values['attach-to-editors']) {
                $values['attach-to'] = (string)$io->ask('Welche Gruppen dann? Titel oder uids, mit Komma getrennt');
            }
            $values['workspace'] = (string)$io->ask('Workspace (wird angelegt oder weiterverwendet)', $values['workspace'] ?? SetupOptions::DEFAULT_WORKSPACE);

            return SetupOptions::fromArray($scenario, $values);
        }

        if ($scenario === SetupScenario::GroupPerWorkspace) {
            $values['group'] = (string)$io->ask('Gruppennamen, mit Komma getrennt (jede bekommt einen eigenen Workspace)', 'News,Sport');
        } else {
            $values['group'] = (string)$io->ask('Name der Redaktionsgruppe', SetupOptions::DEFAULT_GROUP);
            $values['workspace'] = (string)$io->ask('Name des Workspaces', $values['workspace'] ?? CommaList::parse($values['group'])[0] ?? SetupOptions::DEFAULT_WORKSPACE);
        }
        $values['editor'] = (string)$io->ask('Jetzt Redakteure anlegen? Benutzernamen, mit Komma getrennt (leer: spaeter)', '');
        if ($values['editor'] !== '' && $values['editor-password'] === null) {
            $values['editor-password'] = (string)$io->askHidden('Passwort fuer die neuen Redakteure');
        }

        return SetupOptions::fromArray($scenario, $values);
    }

    /**
     * @return array{group: list<string>|string, workspace: ?string, attach-to: list<string>|string, attach-to-editors: bool, site-base: string, dry-run: bool, editor: list<string>|string, editor-password: ?string}
     */
    private function optionValues(InputInterface $input): array
    {
        $workspace = $input->getOption('workspace');

        return [
            'group' => $this->listOption($input, 'group'),
            'workspace' => is_string($workspace) ? $workspace : null,
            'attach-to' => $this->listOption($input, 'attach-to'),
            'attach-to-editors' => (bool)$input->getOption('attach-to-editors'),
            'site-base' => (string)$input->getOption('site-base'),
            'dry-run' => (bool)$input->getOption('dry-run'),
            'editor' => $this->listOption($input, 'editor'),
            'editor-password' => $this->editorPassword($input),
        ];
    }

    /**
     * Aus der Option oder der Umgebung - die Umgebung hat ein CI-Job oder Deploy, und das Passwort bleibt
     * aus der Shell-History.
     */
    private function editorPassword(InputInterface $input): ?string
    {
        $password = $input->getOption('editor-password');
        if (is_string($password) && $password !== '') {
            return $password;
        }
        $fromEnvironment = getenv(self::PASSWORD_VARIABLE);

        return is_string($fromEnvironment) && $fromEnvironment !== '' ? $fromEnvironment : null;
    }

    /**
     * @return list<string>
     */
    private function listOption(InputInterface $input, string $name): array
    {
        return array_values(array_map(strval(...), (array)$input->getOption($name)));
    }
}
