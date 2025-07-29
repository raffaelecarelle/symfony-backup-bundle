# Symfony Backup Bundle

A Symfony bundle for database and filesystem backup/restore management.

## Overview

The Symfony Backup Bundle provides a complete and configurable system for automatic and manual backups of databases and filesystems, with native integration in the Symfony Profiler for development operations.

## Features

- Database backup and restore (MySQL, PostgreSQL, SQLite, SQL Server)
- Filesystem backup and restore
- Multiple storage adapters (Local, S3, Google Cloud)
- Compression support (Gzip, Zip)
- Scheduled backups
- Event system for backup/restore operations
- Symfony Profiler integration
- Command-line interface

## Installation

```bash
composer require symfony/backup-bundle
```

## Configuration

Create a configuration file at `config/packages/backup.yaml`:

```yaml
symfony_backup:
    default_storage: 'local'
    
    storage:
        local:
            adapter: 'local'
            options:
                path: '%kernel.project_dir%/var/backups'
                permissions: 0755
        
        s3:
            adapter: 's3'
            options:
                bucket: 'my-app-backups'
                region: 'eu-west-1'
                credentials:
                    key: '%env(AWS_ACCESS_KEY_ID)%'
                    secret: '%env(AWS_SECRET_ACCESS_KEY)%'
        
        google_cloud:
            adapter: 'google_cloud'
            options:
                bucket: 'my-app-backups'
                project_id: '%env(GOOGLE_CLOUD_PROJECT_ID)%'
                key_file: '%env(GOOGLE_CLOUD_KEY_FILE)%'
    
    database:
        enabled: true
        connections: ['default'] # List of Doctrine connections
        compression: 'gzip'
        retention_days: 30
        exclude_tables: ['cache_items', 'sessions']
        options:
            mysql:
                single_transaction: true
                routines: true
                triggers: true
            postgresql:
                format: 'custom'
                verbose: true
    
    filesystem:
        enabled: false
        paths:
            - { path: '%kernel.project_dir%/public/uploads', exclude: ['*.tmp', '*.log'] }
            - { path: '%kernel.project_dir%/config', exclude: ['secrets/'] }
        compression: 'zip'
        retention_days: 7
    
    schedule:
        database:
            frequency: 'daily' # daily, weekly, monthly, cron expression
            time: '02:00'
        filesystem:
            frequency: 'weekly'
            time: '03:00'
    
    notifications:
        on_success: false
        on_failure: true
        channels: ['email'] # email, slack, webhook
```

## Usage

### Command Line

Create a database backup:

```bash
php bin/console backup:create --type=database
```

Restore a backup:

```bash
php bin/console backup:restore <backup-id>
```

List available backups:

```bash
php bin/console backup:list
```

### Programmatic Usage

```php
use Symfony\Component\Backup\Model\BackupConfiguration;
use Symfony\Component\Backup\Manager\BackupManager;

// Create a backup
$config = new BackupConfiguration();
$config->setType('database');
$config->setName('my_backup');

$backupManager = $container->get(BackupManager::class);
$result = $backupManager->backup($config);

if ($result->isSuccess()) {
    echo "Backup created: " . $result->getFilePath();
} else {
    echo "Backup failed: " . $result->getError();
}

// Restore a backup
$backupId = 'backup_123';
$success = $backupManager->restore($backupId);

if ($success) {
    echo "Backup restored successfully";
} else {
    echo "Restore failed";
}
```

## Events

The bundle dispatches the following events:

- `backup.pre_backup`: Before a backup operation
- `backup.post_backup`: After a successful backup operation
- `backup.failed`: When a backup operation fails
- `backup.pre_restore`: Before a restore operation
- `backup.post_restore`: After a successful restore operation
- `backup.restore_failed`: When a restore operation fails

## Testing

Run the PHPUnit tests:

```bash
vendor/bin/phpunit
```

## License

This bundle is released under the MIT License.