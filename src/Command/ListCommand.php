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
 * Command to list available backups.
 */
#[AsCommand(name: 'backup:list')]
class ListCommand extends Command
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
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by backup type (database|filesystem)')
            ->addOption('storage', 's', InputOption::VALUE_REQUIRED, 'Filter by storage adapter')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (table|json|csv)', 'table')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command lists available backups:

  <info>php %command.full_name%</info>

You can filter by backup type:

  <info>php %command.full_name% --type=database</info>
  <info>php %command.full_name% --type=filesystem</info>

You can filter by storage adapter:

  <info>php %command.full_name% --storage=local</info>
  <info>php %command.full_name% --storage=s3</info>

You can change the output format:

  <info>php %command.full_name% --format=table</info>
  <info>php %command.full_name% --format=json</info>
  <info>php %command.full_name% --format=csv</info>
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = $input->getOption('type');
        $storage = $input->getOption('storage');
        $format = $input->getOption('format');

        // Get backups
        $backups = $this->backupManager->listBackups();

        if ($type) {
            $backups = array_filter($backups, fn ($backup) => $backup['type'] === $type);
        }

        // Filter by storage if specified
        if ($storage) {
            $backups = array_filter($backups, fn ($backup) => $backup['storage'] === $storage);
        }

        // Sort backups by creation date (newest first)
        usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        // Display backups
        if (empty($backups)) {
            if ('json' === $format) {
                $output->writeln('[]');

                return Command::SUCCESS;
            }

            $io->warning('No backups found');

            return Command::SUCCESS;
        }

        // Only show title for non-JSON formats
        if ('json' !== $format) {
            $io->title(\sprintf('Available Backups: %d', \count($backups)));
        }

        // Format backups for display
        $rows = [];
        foreach ($backups as $backup) {
            $rows[] = [
                $backup['id'],
                $backup['type'],
                $backup['name'],
                $this->formatFileSize($backup['file_size']),
                $backup['created_at']->format('Y-m-d H:i:s'),
                $backup['storage'],
            ];
        }

        // Output in the requested format
        match ($format) {
            'json' => $this->outputJson($output, $backups),
            'csv' => $this->outputCsv($output, $rows),
            default => $io->table(
                ['ID', 'Type', 'Name', 'Size', 'Created', 'Storage'],
                $rows
            ),
        };

        // Display storage usage (only for non-JSON formats)
        if ('json' !== $format) {
            $usage = $this->backupManager->getStorageUsage();

            $io->section('Storage Usage');
            $io->table(
                ['Type', 'Size'],
                array_merge(
                    [['Total', $this->formatFileSize($usage['total'])]],
                    array_map(
                        fn ($type, $size) => [$type, $this->formatFileSize($size)],
                        array_keys($usage['by_type']),
                        array_values($usage['by_type'])
                    )
                )
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Output backups as JSON.
     */
    private function outputJson(OutputInterface $output, array $backups): void
    {
        // Format the data for JSON output
        $jsonBackups = [];
        foreach ($backups as $backup) {
            $jsonBackups[] = [
                'id' => $backup['id'],
                'type' => $backup['type'],
                'name' => $backup['name'],
                'file_size' => $backup['file_size'],
                'created_at' => $backup['created_at']->format('Y-m-d H:i:s'),
                'storage' => $backup['storage'],
            ];
        }

        // Ensure we're returning valid JSON
        $jsonData = json_encode($jsonBackups, \JSON_PRETTY_PRINT);
        if (false === $jsonData) {
            // Handle JSON encoding error
            $output->writeln('[]');

            return;
        }

        $output->writeln($jsonData);
    }

    /**
     * Output backups as CSV.
     */
    private function outputCsv(OutputInterface $output, array $rows): void
    {
        // Output header
        $output->writeln('ID,Type,Name,Size,Created,Storage');

        // Output rows
        foreach ($rows as $row) {
            $output->writeln(implode(',', array_map(fn ($value) =>
                // Quote values containing commas
            str_contains((string) $value, ',') ? '"'.$value.'"' : $value, $row)));
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
