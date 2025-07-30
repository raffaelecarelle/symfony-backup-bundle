# Database Driver Resolver

## Overview

This document describes the implementation of a database driver resolver for the Symfony Backup Bundle. The driver resolver automatically determines the appropriate database adapter to use based on the actual database type (MySQL, PostgreSQL, SQLite, SQL Server) using Doctrine connection information.

## Problem

Previously, the bundle used a single "type" parameter (which could be "database" or "filesystem") for two different purposes:
1. To determine if the backup is for a database or filesystem
2. To decide which database adapter to use

This meant that when using the generic "database" type, the bundle would select the first adapter that supported this type, which might not be the appropriate one for the actual database being used.

## Solution

The solution is to implement a driver resolver that can automatically determine the appropriate database adapter based on the actual database type using Doctrine connection information.

### Implementation

1. Created a new `DatabaseDriverResolver` class that:
   - Takes a Doctrine DBAL Connection
   - Provides a `resolveDriverType()` method that determines the specific database type (mysql, postgresql, sqlite, sqlserver)

2. Added a `getConnection()` method to all database adapters:
   - MySQLAdapter
   - PostgreSQLAdapter
   - SQLiteAdapter
   - SqlServerAdapter

3. Updated the `getAdapter()` method in the BackupManager to:
   - Check if the type is 'database'
   - If so, look for a database adapter that has a `getConnection()` method
   - Create a DatabaseDriverResolver with the connection
   - Use the resolver to determine the specific database type
   - Use the specific type to select the appropriate adapter

### Usage

No changes are required in how you use the bundle. When you specify "database" as the type, the bundle will automatically determine the appropriate database adapter based on the actual database type.

```php
// Create a backup configuration
$config = new BackupConfiguration();
$config->setType('database'); // Will automatically resolve to mysql, postgresql, sqlite, or sqlserver

// Perform the backup
$result = $backupManager->backup($config);
```

## Benefits

- Automatic selection of the appropriate database adapter based on the actual database type
- No need to specify the specific database type in the configuration
- Backward compatibility with existing code

## Technical Details

The `DatabaseDriverResolver` uses the following methods to determine the database type:
- `$connection->getDatabasePlatform()->getName()` to get the database platform name
- `$connection->getParams()['driver']` to get the driver name

It then maps these values to the specific adapter types:
- mysql
- postgresql
- sqlite
- sqlserver

If it can't determine the specific type, it falls back to 'database'.