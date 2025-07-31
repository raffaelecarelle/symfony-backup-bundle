<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Model;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\Model\BackupResult;

class BackupResultTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $result = new BackupResult();

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getFilePath());
        $this->assertNull($result->getFileSize());
        $this->assertInstanceOf(\DateTimeImmutable::class, $result->getCreatedAt());
        $this->assertNull($result->getDuration());
        $this->assertNull($result->getError());
        $this->assertEquals([], $result->getMetadata());
        $this->assertNotNull($result->getId());
        $this->assertStringStartsWith('backup_', $result->getId());
    }

    public function testConstructorWithValues(): void
    {
        $createdAt = new \DateTimeImmutable('2023-01-01 10:00:00');
        $result = new BackupResult(
            true,
            '/path/to/backup.sql.gz',
            1024,
            $createdAt,
            1.5,
            null,
            ['type' => 'database']
        );

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('/path/to/backup.sql.gz', $result->getFilePath());
        $this->assertEquals(1024, $result->getFileSize());
        $this->assertSame($createdAt, $result->getCreatedAt());
        $this->assertEquals(1.5, $result->getDuration());
        $this->assertNull($result->getError());
        $this->assertEquals(['type' => 'database'], $result->getMetadata());
    }

    public function testConstructorWithError(): void
    {
        $result = new BackupResult(
            false,
            null,
            null,
            null,
            0.5,
            'Database connection failed'
        );

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getFilePath());
        $this->assertNull($result->getFileSize());
        $this->assertEquals(0.5, $result->getDuration());
        $this->assertEquals('Database connection failed', $result->getError());
    }

    public function testSuccess(): void
    {
        $result = new BackupResult();

        // Default value
        $this->assertFalse($result->isSuccess());

        // Test setter and getter
        $result->setSuccess(true);
        $this->assertTrue($result->isSuccess());

        // Test fluent interface
        $this->assertSame($result, $result->setSuccess(false));
        $this->assertFalse($result->isSuccess());
    }

    public function testFilePath(): void
    {
        $result = new BackupResult();

        // Default value
        $this->assertNull($result->getFilePath());

        // Test setter and getter
        $result->setFilePath('/path/to/backup.sql.gz');
        $this->assertEquals('/path/to/backup.sql.gz', $result->getFilePath());

        // Test fluent interface
        $this->assertSame($result, $result->setFilePath('/new/path.sql.gz'));
        $this->assertEquals('/new/path.sql.gz', $result->getFilePath());

        // Test setting to null
        $result->setFilePath(null);
        $this->assertNull($result->getFilePath());
    }

    public function testFileSize(): void
    {
        $result = new BackupResult();

        // Default value
        $this->assertNull($result->getFileSize());

        // Test setter and getter
        $result->setFileSize(1024);
        $this->assertEquals(1024, $result->getFileSize());

        // Test fluent interface
        $this->assertSame($result, $result->setFileSize(2048));
        $this->assertEquals(2048, $result->getFileSize());

        // Test setting to null
        $result->setFileSize(null);
        $this->assertNull($result->getFileSize());
    }

    public function testCreatedAt(): void
    {
        $result = new BackupResult();

        // Default value should be current time
        $now = new \DateTimeImmutable();
        $this->assertInstanceOf(\DateTimeImmutable::class, $result->getCreatedAt());
        $this->assertLessThanOrEqual(2, $now->getTimestamp() - $result->getCreatedAt()->getTimestamp());

        // Test setter and getter
        $createdAt = new \DateTimeImmutable('2023-01-01 10:00:00');
        $result->setCreatedAt($createdAt);
        $this->assertSame($createdAt, $result->getCreatedAt());

        // Test fluent interface
        $newCreatedAt = new \DateTimeImmutable('2023-01-02 11:00:00');
        $this->assertSame($result, $result->setCreatedAt($newCreatedAt));
        $this->assertSame($newCreatedAt, $result->getCreatedAt());
    }

    public function testDuration(): void
    {
        $result = new BackupResult();

        // Default value
        $this->assertNull($result->getDuration());

        // Test setter and getter
        $result->setDuration(1.5);
        $this->assertEquals(1.5, $result->getDuration());

        // Test fluent interface
        $this->assertSame($result, $result->setDuration(2.5));
        $this->assertEquals(2.5, $result->getDuration());

        // Test setting to null
        $result->setDuration(null);
        $this->assertNull($result->getDuration());
    }

    public function testError(): void
    {
        $result = new BackupResult();

        // Default value
        $this->assertNull($result->getError());

        // Test setter and getter
        $result->setError('Database connection failed');
        $this->assertEquals('Database connection failed', $result->getError());

        // Test fluent interface
        $this->assertSame($result, $result->setError('Permission denied'));
        $this->assertEquals('Permission denied', $result->getError());

        // Test setting to null
        $result->setError(null);
        $this->assertNull($result->getError());
    }

    public function testMetadata(): void
    {
        $result = new BackupResult();

        // Default value
        $this->assertEquals([], $result->getMetadata());

        // Test setter and getter
        $metadata = ['type' => 'database', 'tables' => 5];
        $result->setMetadata($metadata);
        $this->assertEquals($metadata, $result->getMetadata());

        // Test fluent interface
        $newMetadata = ['type' => 'filesystem', 'files' => 100];
        $this->assertSame($result, $result->setMetadata($newMetadata));
        $this->assertEquals($newMetadata, $result->getMetadata());
    }

    public function testMetadataValue(): void
    {
        $result = new BackupResult();

        // Default value for non-existent key
        $this->assertNull($result->getMetadataValue('non_existent'));

        // Test with custom default value
        $this->assertEquals('default', $result->getMetadataValue('non_existent', 'default'));

        // Test setter and getter
        $result->setMetadataValue('type', 'database');
        $this->assertEquals('database', $result->getMetadataValue('type'));

        // Test fluent interface
        $this->assertSame($result, $result->setMetadataValue('tables', 5));
        $this->assertEquals(5, $result->getMetadataValue('tables'));

        // Test that setMetadataValue adds to existing metadata
        $this->assertEquals([
            'type' => 'database',
            'tables' => 5,
        ], $result->getMetadata());
    }

    public function testId(): void
    {
        $result = new BackupResult();

        // Default value should be auto-generated
        $this->assertNotNull($result->getId());
        $this->assertStringStartsWith('backup_', $result->getId());

        // Test setter and getter
        $result->setId('custom_id');
        $this->assertEquals('custom_id', $result->getId());

        // Test fluent interface
        $this->assertSame($result, $result->setId('another_id'));
        $this->assertEquals('another_id', $result->getId());

        // Test setting to null
        $result->setId(null);
        $this->assertNull($result->getId());
    }
}
