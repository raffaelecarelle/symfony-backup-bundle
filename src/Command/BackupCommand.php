<?php

namespace ProBackupBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ProBackupBundle\Manager\BackupManager;
use ProBackupBundle\Model\BackupConfiguration;

/**
 * Command to create a backup.
 */
class BackupCommand extends Command
{
    protected static $defaultName = 'backup:create';
    protected static $defaultDescription = 'Create a backup of database or filesystem';
    
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
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Backup type (database|filesystem)', 'database')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Backup name')
            ->addOption('storage', 's', InputOption::VALUE_REQUIRED, 'Storage adapter to use')
            ->addOption('compression', 'c', InputOption::VALUE_REQUIRED, 'Compression type (gzip|zip)')
            ->addOption('output-path', 'o', InputOption::VALUE_REQUIRED, 'Custom output path for the backup file')
            ->addOption('exclude', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tables or paths to exclude')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command creates a backup of the database or filesystem:

  <info>php %command.full_name%</info>

You can specify the type of backup:

  <info>php %command.full_name% --type=database</info>
  <info>php %command.full_name% --type=filesystem</info>

You can specify a custom name for the backup:

  <info>php %command.full_name% --name=my_backup</info>

You can specify a storage adapter:

  <info>php %command.full_name% --storage=s3</info>

You can specify a compression type:

  <info>php %command.full_name% --compression=gzip</info>

You can specify a custom output path:

  <info>php %command.full_name% --output-path=/path/to/backups</info>

You can exclude tables or paths:

  <info>php %command.full_name% --exclude=cache_items --exclude=sessions</info>
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
        $name = $input->getOption('name') ?: sprintf('%s_%s', $type, date('Y-m-d_H-i-s'));
        $storage = $input->getOption('storage');
        $compression = $input->getOption('compression');
        $outputPath = $input->getOption('output-path');
        $exclusions = $input->getOption('exclude');
        
        $io->title('Creating Backup');
        $io->table(
            ['Option', 'Value'],
            [
                ['Type', $type],
                ['Name', $name],
                ['Storage', $storage ?: '(default)'],
                ['Compression', $compression ?: '(none)'],
                ['Output Path', $outputPath ?: '(default)'],
                ['Exclusions', $exclusions ? implode(', ', $exclusions) : '(none)'],
            ]
        );
        
        $config = new BackupConfiguration();
        $config->setType($type);
        $config->setName($name);
        
        if ($storage) {
            $config->setStorage($storage);
        }
        
        if ($compression) {
            $config->setCompression($compression);
        }
        
        if ($outputPath) {
            $config->setOutputPath($outputPath);
        }
        
        if ($exclusions) {
            $config->setExclusions($exclusions);
        }
        
        $io->section('Starting backup process');
        $io->progressStart(3);
        
        $io->progressAdvance();
        $io->text('Preparing backup...');
        
        try {
            $result = $this->backupManager->backup($config);
            
            $io->progressAdvance();
            $io->text('Processing backup...');
            
            $io->progressAdvance();
            $io->progressFinish();
            
            if ($result->isSuccess()) {
                $io->success([
                    'Backup created successfully',
                    sprintf('File: %s', $result->getFilePath()),
                    sprintf('Size: %s', $this->formatFileSize($result->getFileSize())),
                    sprintf('Duration: %.2f seconds', $result->getDuration()),
                ]);
                
                return Command::SUCCESS;
            }
            
            $io->error([
                'Backup failed',
                sprintf('Error: %s', $result->getError()),
            ]);
            
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->progressFinish();
            
            $io->error([
                'Backup failed with exception',
                sprintf('Error: %s', $e->getMessage()),
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