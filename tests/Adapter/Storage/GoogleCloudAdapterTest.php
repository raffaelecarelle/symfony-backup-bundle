<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\Adapter\Storage;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProBackupBundle\Adapter\Storage\GoogleCloudAdapter;
use Psr\Log\LoggerInterface;

class GoogleCloudAdapterTest extends TestCase
{
    private MockObject $client;

    private MockObject $bucket;

    private MockObject $logger;

    private GoogleCloudAdapter $adapter;

    protected function setUp(): void
    {
        $this->client = $this->createMock(StorageClient::class);
        $this->bucket = $this->createMock(Bucket::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->client->method('bucket')->willReturn($this->bucket);

        $this->adapter = new GoogleCloudAdapter($this->client, 'my-bucket', 'backups', $this->logger);
    }

    public function testStoreSuccess(): void
    {
        $local = sys_get_temp_dir() . '/gcs_local_' . uniqid('', true) . '.txt';
        file_put_contents($local, 'content');

        $this->bucket->expects($this->once())
            ->method('upload')
            ->with($this->callback(fn ($r): bool => \is_resource($r)), ['name' => 'backups/remote/file.txt']);

        $this->assertTrue($this->adapter->store($local, 'remote/file.txt'));
        @unlink($local);
    }

    public function testStoreFailsWhenLocalMissing(): void
    {
        $this->assertFalse($this->adapter->store('/no/file.txt', 'remote/file.txt'));
    }

    public function testRetrieveSuccess(): void
    {
        $remote = 'db/data.tar.gz';
        $local = sys_get_temp_dir() . '/gcs_download_' . uniqid('', true) . '.tar.gz';

        $object = $this->createMock(StorageObject::class);
        $object->expects($this->once())->method('exists')->willReturn(true);
        $object->expects($this->once())->method('downloadToFile')->with($local);

        $this->bucket->expects($this->once())
            ->method('object')
            ->with('backups/' . $remote)
            ->willReturn($object);

        $this->assertTrue($this->adapter->retrieve($remote, $local));
    }

    public function testRetrieveFailsIfNotExists(): void
    {
        $object = $this->createMock(StorageObject::class);
        $object->expects($this->once())->method('exists')->willReturn(false);
        $this->bucket->method('object')->willReturn($object);

        $this->assertFalse($this->adapter->retrieve('missing.tar.gz', sys_get_temp_dir() . '/out_' . uniqid('', true)));
    }

    public function testDeleteSuccess(): void
    {
        $object = $this->createMock(StorageObject::class);
        $object->expects($this->once())->method('exists')->willReturn(true);
        $object->expects($this->once())->method('delete');
        $this->bucket->method('object')->willReturn($object);

        $this->assertTrue($this->adapter->delete('old.zip'));
    }

    public function testDeleteReturnsTrueIfAlreadyMissing(): void
    {
        $object = $this->createMock(StorageObject::class);
        $object->expects($this->once())->method('exists')->willReturn(false);
        $this->bucket->method('object')->willReturn($object);

        $this->assertTrue($this->adapter->delete('none.zip'));
    }

    public function testListObjects(): void
    {
        $obj1 = $this->createConfiguredMock(StorageObject::class, [
            'name' => 'backups/db/a.sql.gz',
            'info' => ['size' => 10, 'updated' => '2024-01-01T00:00:00Z'],
        ]);
        $obj2 = $this->createConfiguredMock(StorageObject::class, [
            'name' => 'backups/db/b.sql.gz',
            'info' => ['size' => 20, 'updated' => '2024-01-02T00:00:00Z'],
        ]);

        $this->bucket->expects($this->once())
            ->method('objects')
            ->with(['prefix' => 'backups/db/'])
            ->willReturn([$obj1, $obj2]);

        $files = $this->adapter->list('db/');
        $this->assertCount(2, $files);
        $this->assertSame('db/a.sql.gz', $files[0]['path']);
        $this->assertSame(10, $files[0]['size']);
    }

    public function testExists(): void
    {
        $object = $this->createMock(StorageObject::class);
        $object->expects($this->once())->method('exists')->willReturn(true);
        $this->bucket->method('object')->willReturn($object);

        $this->assertTrue($this->adapter->exists('x.tar.gz'));
    }
}
