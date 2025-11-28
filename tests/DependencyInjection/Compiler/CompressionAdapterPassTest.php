<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\DependencyInjection\Compiler\CompressionAdapterPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class CompressionAdapterPassTest extends TestCase
{
    private CompressionAdapterPass $compilerPass;

    private ContainerBuilder $containerBuilder;

    private Definition $managerDefinition;

    protected function setUp(): void
    {
        $this->compilerPass = new CompressionAdapterPass();
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
        $gzipDefinition = new Definition();
        $gzipDefinition->addTag('pro_backup.compression_adapter', ['name' => 'gzip']);

        $this->containerBuilder->setDefinition('pro_backup.compression.gzip', $gzipDefinition);

        $zipDefinition = new Definition();
        $zipDefinition->addTag('pro_backup.compression_adapter', ['name' => 'zip']);

        $this->containerBuilder->setDefinition('pro_backup.compression.zip', $zipDefinition);

        // Process the container
        $this->compilerPass->process($this->containerBuilder);

        // Verify that method calls were added to the manager
        $methodCalls = $this->managerDefinition->getMethodCalls();
        $this->assertCount(2, $methodCalls);

        // Check the first method call
        $this->assertEquals('addCompressionAdapter', $methodCalls[0][0]);
        $this->assertEquals('gzip', $methodCalls[0][1][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[0][1][1]);
        $this->assertEquals('pro_backup.compression.gzip', (string) $methodCalls[0][1][1]);

        // Check the second method call
        $this->assertEquals('addCompressionAdapter', $methodCalls[1][0]);
        $this->assertEquals('zip', $methodCalls[1][1][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[1][1][1]);
        $this->assertEquals('pro_backup.compression.zip', (string) $methodCalls[1][1][1]);
    }

    public function testProcessWithTaggedServicesWithoutName(): void
    {
        // Create and register a tagged service without a name
        $gzipDefinition = new Definition();
        $gzipDefinition->addTag('pro_backup.compression_adapter');

        $this->containerBuilder->setDefinition('pro_backup.compression.gzip', $gzipDefinition);

        // Process the container
        $this->compilerPass->process($this->containerBuilder);

        // Verify that a method call was added to the manager
        $methodCalls = $this->managerDefinition->getMethodCalls();
        $this->assertCount(1, $methodCalls);

        // Check that the name was extracted from the service ID
        $this->assertEquals('addCompressionAdapter', $methodCalls[0][0]);
        $this->assertEquals('gzip', $methodCalls[0][1][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[0][1][1]);
        $this->assertEquals('pro_backup.compression.gzip', (string) $methodCalls[0][1][1]);
    }

    public function testProcessWithMultipleTags(): void
    {
        // Create and register a service with multiple tags
        $gzipDefinition = new Definition();
        $gzipDefinition->addTag('pro_backup.compression_adapter', ['name' => 'gzip']);
        $gzipDefinition->addTag('pro_backup.compression_adapter', ['name' => 'gz']);

        $this->containerBuilder->setDefinition('pro_backup.compression.gzip', $gzipDefinition);

        // Process the container
        $this->compilerPass->process($this->containerBuilder);

        // Verify that multiple method calls were added to the manager
        $methodCalls = $this->managerDefinition->getMethodCalls();
        $this->assertCount(2, $methodCalls);

        // Check the first method call
        $this->assertEquals('addCompressionAdapter', $methodCalls[0][0]);
        $this->assertEquals('gzip', $methodCalls[0][1][0]);

        // Check the second method call
        $this->assertEquals('addCompressionAdapter', $methodCalls[1][0]);
        $this->assertEquals('gz', $methodCalls[1][1][0]);
    }

    public function testProcessWithNoManagerDefinition(): void
    {
        // Create a new container without the manager definition
        $containerBuilder = new ContainerBuilder();

        // Create and register a tagged service
        $gzipDefinition = new Definition();
        $gzipDefinition->addTag('pro_backup.compression_adapter', ['name' => 'gzip']);

        $containerBuilder->setDefinition('pro_backup.compression.gzip', $gzipDefinition);

        // Process the container
        $this->compilerPass->process($containerBuilder);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testGetNameFromServiceId(): void
    {
        // Use reflection to access the private method
        $reflectionClass = new \ReflectionClass(CompressionAdapterPass::class);
        $method = $reflectionClass->getMethod('getNameFromServiceId');

        // Test with a service ID that has a dot
        $this->assertEquals('gzip', $method->invoke($this->compilerPass, 'pro_backup.compression.gzip'));

        // Test with a service ID that has multiple dots
        $this->assertEquals('gzip', $method->invoke($this->compilerPass, 'app.pro_backup.compression.gzip'));

        // Test with a service ID that has no dots
        $this->assertEquals('gzip_compression', $method->invoke($this->compilerPass, 'gzip_compression'));
    }
}
