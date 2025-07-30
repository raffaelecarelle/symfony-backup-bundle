<?php

declare(strict_types=1);

namespace ProBackupBundle\Command;

use ProBackupBundle\Manager\BackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to restore a backup.
 */
#[AsCommand(name: 'backup:restore')]
class RestoreCommand extends Command
{
    /**
     * Constructor.
     */
    public function __construct(private readonly BackupManager $backupManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('backup-id', InputArgument::REQUIRED, 'ID of the backup to restore')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force restore without confirmation')
            ->addOption('single-user', null, InputOption::VALUE_NONE, 'Set database to single user mode during restore (SQL Server only)')
            ->addOption('recovery', null, InputOption::VALUE_REQUIRED, 'Recovery option (SQL Server only): recovery|norecovery')
            ->addOption('backup-existing', null, InputOption::VALUE_NONE, 'Create a backup of the existing database before restore (SQLite only)')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command restores a backup:

  <info>php %command.full_name% backup_id</info>

You can force the restore without confirmation:

  <info>php %command.full_name% backup_id --force</info>

SQL Server specific options:

  <info>php %command.full_name% backup_id --single-user</info>
  <info>php %command.full_name% backup_id --recovery=norecovery</info>

SQLite specific options:

  <info>php %command.full_name% backup_id --backup-existing</info>
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $backupId = $input->getArgument('backup-id');
        $force = $input->getOption('force');

        // Get the backup details
        $backup = $this->backupManager->getBackup($backupId);

        if (!$backup) {
            $io->error(\sprintf('Backup with ID "%s" not found', $backupId));

            return Command::FAILURE;
        }

        $io->title('Restore Backup');
        $io->table(
            ['Property', 'Value'],
            [
                ['ID', $backup['id']],
                ['Type', $backup['type']],
                ['Name', $backup['name']],
                ['File', $backup['file_path']],
                ['Size', $this->formatFileSize($backup['file_size'])],
                ['Created', $backup['created_at']->format('Y-m-d H:i:s')],
                ['Storage', $backup['storage']],
            ]
        );

        // Confirm restore
        if (!$force) {
            $question = new ConfirmationQuestion('Are you sure you want to restore this backup? This action cannot be undone! (y/n) ', false);

            if (!$io->askQuestion($question)) {
                $io->warning('Restore cancelled');

                return Command::SUCCESS;
            }
        }

        // Prepare restore options
        $options = [];

        if ($input->getOption('single-user')) {
            $options['single_user'] = true;
        }

        if ($input->getOption('recovery')) {
            $options['recovery'] = $input->getOption('recovery');
        }

        if ($input->getOption('backup-existing')) {
            $options['backup_existing'] = true;
        }

        $io->section('Starting restore process');
        $io->progressStart(3);

        $io->progressAdvance();
        $io->text('Preparing restore...');

        try {
            $io->progressAdvance();
            $io->text('Restoring backup...');

            $success = $this->backupManager->restore($backupId, $options);

            $io->progressAdvance();
            $io->progressFinish();

            if ($success) {
                $io->success('Backup restored successfully');

                return Command::SUCCESS;
            }

            $io->error('Restore failed');

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->progressFinish();

            $io->error([
                'Restore failed with exception',
                \sprintf('Error: %s', $e->getMessage()),
            ]);

            if ($output->isVerbose()) {
                $io->section('Exception details');
                $io->text((string) $e);
            }

            return Command::FAILURE;
        }
    }

    /**
     * Format file size in human-readable format.
     *
     * @param int $bytes     File size in bytes
     * @param int $precision Precision of the result
     *
     * @return string Formatted file size
     */
    private function formatFileSize(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, \count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
