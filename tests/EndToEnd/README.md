# End-to-End Tests for ProBackupBundle

This directory contains end-to-end tests for the ProBackupBundle. These tests verify the functionality of the bundle in a real environment with actual components instead of mocks.

## Test Structure

The end-to-end tests are organized as follows:

- `AbstractEndToEndTest.php`: Base class for all end-to-end tests that sets up a real BackupManager with actual adapters.
- `SQLiteDatabaseBackupTest.php`: Tests for SQLite database backup functionality.
- `FilesystemBackupTest.php`: Tests for filesystem backup functionality.
- `CommandExecutionTest.php`: Tests for command execution with real components.
- `ScheduledBackupTest.php`: Tests for scheduled backup functionality.

## Running the Tests

To run the end-to-end tests, use the following command from the project root:

```bash
vendor/bin/phpunit tests/EndToEnd/
```

To run a specific test class:

```bash
vendor/bin/phpunit tests/EndToEnd/SQLiteDatabaseBackupTest.php
```

## Test Requirements

The end-to-end tests require:

1. PHP 7.4 or higher
2. SQLite extension enabled
3. Sufficient permissions to create temporary files and directories

## Test Environment

The tests create temporary files and directories for testing purposes. These are automatically cleaned up after each test.

## Adding New Tests

To add a new end-to-end test:

1. Create a new class that extends `AbstractEndToEndTest`
2. Implement the `setupTest()` method to set up any specific requirements
3. Add test methods that verify the functionality being tested

Example:

```php
<?php

namespace ProBackupBundle\Tests\EndToEnd;

use ProBackupBundle\Model\BackupConfiguration;

class MyNewTest extends AbstractEndToEndTest
{
    protected function setupTest(): void
    {
        // Setup specific to this test
    }
    
    public function testSomeFeature(): void
    {
        // Test implementation
    }
}
```

## Troubleshooting

If you encounter issues running the tests:

1. Ensure PHP is available in your environment
2. Check that all required PHP extensions are enabled
3. Verify that the temporary directory is writable
4. Check the PHPUnit configuration in `phpunit.xml.dist`