<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3\Command;

use Novaverso\SynchronixAgentTypo3\Pairing\CredentialStore;
use Novaverso\SynchronixAgentTypo3\Pairing\PairingClient;
use Novaverso\SynchronixAgentTypo3\Pairing\PairingException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pairs this installation with Synchronix (companion protocol, section 4).
 *
 * A command rather than a backend module for now: the audience are agencies with
 * CLI access, and a module is a lot of interface for one button. The gap is
 * known - a site without shell access cannot pair yet.
 */
#[AsCommand(
    name: 'synchronix:pair',
    description: 'Verbindet diese TYPO3-Installation mit Synchronix',
)]
final class PairCommand extends Command
{
    public function __construct(
        private readonly PairingClient $client,
        private readonly CredentialStore $credentials,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Auch koppeln, wenn diese Instanz schon gekoppelt ist')
            ->addOption('unpair', null, InputOption::VALUE_NONE, 'Kopplung loesen und die Zugangsdaten loeschen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('unpair')) {
            $this->credentials->forget();
            $io->success('Kopplung geloest. Der Agent antwortet ab jetzt auf jede Anfrage mit 404.');

            return Command::SUCCESS;
        }

        $existing = $this->credentials->load();

        if (null !== $existing && !$input->getOption('force')) {
            $io->warning(sprintf(
                'Diese Installation ist bereits als "%s" gekoppelt. Mit --force wird die Kopplung ersetzt.',
                $existing->instanceId,
            ));

            return Command::SUCCESS;
        }

        try {
            $credentials = $this->client->pair();
        } catch (PairingException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Gekoppelt als "%s".', $credentials->instanceId));
        $io->writeln('Das Kopplungstoken ist verbraucht und kann aus der Extension-Konfiguration entfernt werden.');

        return Command::SUCCESS;
    }
}
