<?php

declare(strict_types=1);

namespace ProBackupBundle\Command;

use ProBackupBundle\Manager\BackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to apply retention policy and purge old backups.
 */
#[AsCommand(name: 'pro:backup:purge', description: 'Apply retention policy and delete old backups')]
class PurgeCommand extends Command
{
    public function __construct(
        private readonly BackupManager $backupManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Backup type to purge (database|filesystem|all)', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not delete anything, only show what would be deleted')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = (string) $input->getOption('type');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!\in_array($type, ['database', 'filesystem', 'all'], true)) {
            $io->error('Invalid type. Allowed values: database, filesystem, all');

            return Command::INVALID;
        }

        $io->title('Applying retention policy');
        $io->text(\sprintf('Type: %s', $type));
        $io->text(\sprintf('Mode: %s', $dryRun ? 'dry-run' : 'execute'));

        try {
            $applyType = 'all' === $type ? null : $type;
            $this->backupManager->applyRetentionPolicy($applyType, $dryRun);
        } catch (\Throwable $e) {
            $io->error('Failed to apply retention: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Retention policy applied');

        return Command::SUCCESS;
    }
}
