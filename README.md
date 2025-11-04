# Symfony Backup Bundle

A Symfony bundle for database and filesystem backup/restore management.

## Overview

The Symfony Backup Bundle provides a complete and configurable system for automatic and manual backups of databases and filesystems, with optional Symfony Profiler integration for development.

## Features

- Database backup and restore (MySQL, PostgreSQL, SQLite, SQL Server)
- Filesystem backup and restore
- Multiple storage adapters (Local, S3, Google Cloud)
- Compression support (gzip, zip)
- Retention policy helpers
- Scheduler/Messenger integration for automated backups (Symfony 7.3+)
- Event system for backup/restore operations
- Command-line interface and programmatic API

## Requirements

- PHP: >= 8.2
- Symfony: 5.4+

## Installation

```bash
composer require raffaelecarelle/symfony-backup-bundle
```

## Configuration

Create a configuration file at `config/packages/pro_backup.yaml`:

```yaml
pro_backup:
    default_storage: 'local'
    backup_dir: '%kernel.project_dir%/var/backups'

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
        connections: ['default'] # Doctrine connection names
        compression: 'gzip'      # gzip|zip|null
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
```

## Usage

### Console commands

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

### Programmatic usage

```php
use ProBackupBundle\Model\BackupConfiguration;
use ProBackupBundle\Manager\BackupManager;

// Create a backup
$config = (new BackupConfiguration())
    ->setType('database')
    ->setName('my_backup');

$backupManager = $container->get(BackupManager::class);
$result = $backupManager->backup($config);

if ($result->isSuccess()) {
    echo 'Backup created: ' . $result->getFilePath();
} else {
    echo 'Backup failed: ' . $result->getError();
}

// Restore a backup
$backupId = 'backup_123';
$success = $backupManager->restore($backupId, []);

if ($success) {
    echo 'Backup restored successfully';
} else {
    echo 'Restore failed';
}
```

## Events

The bundle dispatches the following events (see `ProBackupBundle\Event\BackupEvents`):

- `backup.pre_backup`: Before a backup operation
- `backup.post_backup`: After a successful backup operation
- `backup.failed`: When a backup operation fails
- `backup.pre_restore`: Before a restore operation
- `backup.post_restore`: After a successful restore operation
- `backup.restore_failed`: When a restore operation fails

## Development

- Run CS fixer (dry-run): `vendor/bin/php-cs-fixer fix --dry-run --diff`
- Run static analysis: `vendor/bin/phpstan analyse`
- Run tests: `vendor/bin/phpunit`

CI runs on GitHub Actions with MySQL and PostgreSQL services and a matrix of PHP/Symfony versions compatible with this bundle.

## License

This bundle is released under the MIT License.