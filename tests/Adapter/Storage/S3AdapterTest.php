<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Storage;

use Aws\S3\S3Client;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Storage\S3Adapter;
use Psr\Log\LoggerInterface;

class S3AdapterTest extends TestCase
{
    private string $bucket = 'test-bucket';

    private string $prefix = 'backups';

    private MockObject $s3;

    private MockObject $logger;

    private S3Adapter $adapter;

    protected function setUp(): void
    {
        $this->s3 = $this->getMockBuilder(S3Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doesObjectExist'])
            ->addMethods(['putObject', 'getObject', 'listObjectsV2', 'deleteObject'])
            ->getMock();

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->adapter = new S3Adapter($this->s3, $this->bucket, $this->prefix, $this->logger);
    }

    public function testStoreSuccess(): void
    {
        $local = sys_get_temp_dir() . '/s3_local_' . uniqid('', true) . '.txt';
        file_put_contents($local, 'content');

        $this->s3->expects($this->once())
            ->method('putObject')
            ->with($this->callback(fn ($args): bool => 'test-bucket' === $args['Bucket']
                && 'backups/remote/file.txt' === $args['Key']
                && $args['SourceFile'] === $local));

        $this->assertTrue($this->adapter->store($local, 'remote/file.txt'));

        @unlink($local);
    }

    public function testStoreFailsWhenLocalMissing(): void
    {
        $this->assertFalse($this->adapter->store('/path/not/exist.txt', 'remote/missing.txt'));
    }

    public function testRetrieveSuccess(): void
    {
        $remote = 'remote/data.bin';
        $local = sys_get_temp_dir() . '/s3_download_' . uniqid('', true) . '.bin';

        $this->s3->expects($this->once())
            ->method('doesObjectExist')
            ->with('test-bucket', 'backups/' . $remote)
            ->willReturn(true);

        $this->s3->expects($this->once())
            ->method('getObject')
            ->with($this->callback(fn ($args): bool => 'test-bucket' === $args['Bucket']
                && $args['Key'] === 'backups/' . $remote
                && $args['SaveAs'] === $local));

        $this->assertTrue($this->adapter->retrieve($remote, $local));
    }

    public function testRetrieveFailsIfNotExists(): void
    {
        $this->s3->expects($this->once())
            ->method('doesObjectExist')
            ->with('test-bucket', 'backups/missing.txt')
            ->willReturn(false);

        $this->assertFalse($this->adapter->retrieve('missing.txt', sys_get_temp_dir() . '/whatever_' . uniqid('', true)));
    }

    public function testDeleteSuccess(): void
    {
        $this->s3->expects($this->once())
            ->method('doesObjectExist')
            ->with('test-bucket', 'backups/old.zip')
            ->willReturn(true);

        $this->s3->expects($this->once())
            ->method('deleteObject')
            ->with(['Bucket' => 'test-bucket', 'Key' => 'backups/old.zip']);

        $this->assertTrue($this->adapter->delete('old.zip'));
    }

    public function testDeleteReturnsTrueIfAlreadyMissing(): void
    {
        $this->s3->expects($this->once())
            ->method('doesObjectExist')
            ->with('test-bucket', 'backups/missing.zip')
            ->willReturn(false);

        $this->assertTrue($this->adapter->delete('missing.zip'));
    }

    public function testListObjects(): void
    {
        $this->s3->expects($this->once())
            ->method('listObjectsV2')
            ->with(['Bucket' => 'test-bucket', 'Prefix' => 'backups/db/'])
            ->willReturn([
                'Contents' => [
                    ['Key' => 'backups/db/a.sql.gz', 'Size' => 10, 'LastModified' => '2024-01-01'],
                    ['Key' => 'backups/db/b.sql.gz', 'Size' => 20, 'LastModified' => '2024-01-02'],
                ],
            ]);

        $files = $this->adapter->list('db/');
        $this->assertCount(2, $files);
        $this->assertSame('db/a.sql.gz', $files[0]['path']);
        $this->assertSame(10, $files[0]['size']);
    }

    public function testExists(): void
    {
        $this->s3->expects($this->once())
            ->method('doesObjectExist')
            ->with('test-bucket', 'backups/x.tar.gz')
            ->willReturn(true);

        $this->assertTrue($this->adapter->exists('x.tar.gz'));
    }
}
