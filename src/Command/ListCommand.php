<?php

namespace ProBackupBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ProBackupBundle\Manager\BackupManager;

/**
 * Command to list available backups.
 */
class ListCommand extends Command
{
    protected static $defaultName = 'backup:list';
    protected static $defaultDescription = 'List available backups';
    
    /**
     * @var BackupManager
     */
    private BackupManager $backupManager;
    
    /**
     * Constructor.
     *
     * @param BackupManager $backupManager
     */
    public function __construct(BackupManager $backupManager)
    {
        $this->backupManager = $backupManager;
        
        parent::__construct();
    }
    
    /**
     * {@inheritdoc}
     */
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
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $type = $input->getOption('type');
        $storage = $input->getOption('storage');
        $format = $input->getOption('format');
        
        // Get backups
        $backups = $this->backupManager->listBackups($type);
        
        // Filter by storage if specified
        if ($storage) {
            $backups = array_filter($backups, function ($backup) use ($storage) {
                return $backup['storage'] === $storage;
            });
        }
        
        // Sort backups by creation date (newest first)
        usort($backups, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });
        
        // Display backups
        if (empty($backups)) {
            $io->warning('No backups found');
            return Command::SUCCESS;
        }
        
        $io->title(sprintf('Available Backups: %d', count($backups)));
        
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
        switch ($format) {
            case 'json':
                $this->outputJson($output, $backups);
                break;
                
            case 'csv':
                $this->outputCsv($output, $rows);
                break;
                
            case 'table':
            default:
                $io->table(
                    ['ID', 'Type', 'Name', 'Size', 'Created', 'Storage'],
                    $rows
                );
                break;
        }
        
        // Display storage usage
        $usage = $this->backupManager->getStorageUsage();
        
        $io->section('Storage Usage');
        $io->table(
            ['Type', 'Size'],
            array_merge(
                [['Total', $this->formatFileSize($usage['total'])]],
                array_map(
                    function ($type, $size) {
                        return [$type, $this->formatFileSize($size)];
                    },
                    array_keys($usage['by_type']),
                    array_values($usage['by_type'])
                )
            )
        );
        
        return Command::SUCCESS;
    }
    
    /**
     * Output backups as JSON.
     *
     * @param OutputInterface $output
     * @param array $backups
     */
    private function outputJson(OutputInterface $output, array $backups): void
    {
        // Convert DateTimeImmutable objects to strings
        $backups = array_map(function ($backup) {
            $backup['created_at'] = $backup['created_at']->format('Y-m-d H:i:s');
            return $backup;
        }, $backups);
        
        $output->writeln(json_encode($backups, JSON_PRETTY_PRINT));
    }
    
    /**
     * Output backups as CSV.
     *
     * @param OutputInterface $output
     * @param array $rows
     */
    private function outputCsv(OutputInterface $output, array $rows): void
    {
        // Output header
        $output->writeln('ID,Type,Name,Size,Created,Storage');
        
        // Output rows
        foreach ($rows as $row) {
            $output->writeln(implode(',', array_map(function ($value) {
                // Quote values containing commas
                return strpos($value, ',') !== false ? '"' . $value . '"' : $value;
            }, $row)));
        }
    }
    
    /**
     * Format file size in human-readable format.
     *
     * @param int $bytes File size in bytes
     * @param int $precision Precision of the result
     * 
     * @return string Formatted file size
     */
    private function formatFileSize(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}