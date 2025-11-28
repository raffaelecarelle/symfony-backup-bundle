<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\DependencyInjection\Compiler\StorageAdapterPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class StorageAdapterPassTest extends TestCase
{
    private StorageAdapterPass $compilerPass;

    private ContainerBuilder $containerBuilder;

    private Definition $managerDefinition;

    protected function setUp(): void
    {
        $this->compilerPass = new StorageAdapterPass();
        $this->containerBuilder = new ContainerBuilder();

        // Create a mock manager definition
        $this->managerDefinition = new Definition();
        $this->containerBuilder->setDefinition('pro_backup.manager', $this->managerDefinition);
    }

    public function testProcessWithNoTaggedServices(): void
    {
        // Process the container with no tagged services
        $this->compilerPass->process($this->containerBuilder);

        // Verify that no method calls were added to the manager
        $this->assertEmpty($this->managerDefinition->getMethodCalls());
    }

    public function testProcessWithTaggedServices(): void
    {
        // Create and register some tagged services
        $localDefinition = new Definition();
        $localDefinition->addTag('pro_backup.storage_adapter', ['name' => 'local']);

        $this->containerBuilder->setDefinition('pro_backup.storage.local', $localDefinition);

        $s3Definition = new Definition();
        $s3Definition->addTag('pro_backup.storage_adapter', ['name' => 's3']);

        $this->containerBuilder->setDefinition('pro_backup.storage.s3', $s3Definition);

        // Process the container
        $this->compilerPass->process($this->containerBuilder);

        // Verify that method calls were added to the manager
        $methodCalls = $this->managerDefinition->getMethodCalls();
        $this->assertCount(2, $methodCalls);

        // Check the first method call
        $this->assertEquals('addStorageAdapter', $methodCalls[0][0]);
        $this->assertEquals('local', $methodCalls[0][1][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[0][1][1]);
        $this->assertEquals('pro_backup.storage.local', (string) $methodCalls[0][1][1]);

        // Check the second method call
        $this->assertEquals('addStorageAdapter', $methodCalls[1][0]);
        $this->assertEquals('s3', $methodCalls[1][1][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[1][1][1]);
        $this->assertEquals('pro_backup.storage.s3', (string) $methodCalls[1][1][1]);
    }

    public function testProcessWithTaggedServicesWithoutName(): void
    {
        // Create and register a tagged service without a name
        $localDefinition = new Definition();
        $localDefinition->addTag('pro_backup.storage_adapter');

        $this->containerBuilder->setDefinition('pro_backup.storage.local', $localDefinition);

        // Process the container
        $this->compilerPass->process($this->containerBuilder);

        // Verify that a method call was added to the manager
        $methodCalls = $this->managerDefinition->getMethodCalls();
        $this->assertCount(1, $methodCalls);

        // Check that the name was extracted from the service ID
        $this->assertEquals('addStorageAdapter', $methodCalls[0][0]);
        $this->assertEquals('local', $methodCalls[0][1][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[0][1][1]);
        $this->assertEquals('pro_backup.storage.local', (string) $methodCalls[0][1][1]);
    }

    public function testProcessWithMultipleTags(): void
    {
        // Create and register a service with multiple tags
        $localDefinition = new Definition();
        $localDefinition->addTag('pro_backup.storage_adapter', ['name' => 'local']);
        $localDefinition->addTag('pro_backup.storage_adapter', ['name' => 'filesystem']);

        $this->containerBuilder->setDefinition('pro_backup.storage.local', $localDefinition);

        // Process the container
        $this->compilerPass->process($this->containerBuilder);

        // Verify that multiple method calls were added to the manager
        $methodCalls = $this->managerDefinition->getMethodCalls();
        $this->assertCount(2, $methodCalls);

        // Check the first method call
        $this->assertEquals('addStorageAdapter', $methodCalls[0][0]);
        $this->assertEquals('local', $methodCalls[0][1][0]);

        // Check the second method call
        $this->assertEquals('addStorageAdapter', $methodCalls[1][0]);
        $this->assertEquals('filesystem', $methodCalls[1][1][0]);
    }

    public function testProcessWithNoManagerDefinition(): void
    {
        // Create a new container without the manager definition
        $containerBuilder = new ContainerBuilder();

        // Create and register a tagged service
        $localDefinition = new Definition();
        $localDefinition->addTag('pro_backup.storage_adapter', ['name' => 'local']);

        $containerBuilder->setDefinition('pro_backup.storage.local', $localDefinition);

        // Process the container
        $this->compilerPass->process($containerBuilder);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testGetNameFromServiceId(): void
    {
        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(StorageAdapterPass::class);
        $method = $reflectionClass->getMethod('getNameFromServiceId');

        // Test with a service ID that has a dot
        $this->assertEquals('local', $method->invoke($this->compilerPass, 'pro_backup.storage.local'));

        // Test with a service ID that has multiple dots
        $this->assertEquals('s3', $method->invoke($this->compilerPass, 'app.pro_backup.storage.s3'));

        // Test with a service ID that has no dots
        $this->assertEquals('local_storage', $method->invoke($this->compilerPass, 'local_storage'));
    }
}
