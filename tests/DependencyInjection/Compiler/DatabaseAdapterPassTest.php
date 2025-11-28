<?php

declare(strict_types=1);

namespace ProBackupBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use ProBackupBundle\DependencyInjection\Compiler\DatabaseAdapterPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class DatabaseAdapterPassTest extends TestCase
{
    private DatabaseAdapterPass $compilerPass;

    private ContainerBuilder $containerBuilder;

    private Definition $managerDefinition;

    protected function setUp(): void
    {
        $this->compilerPass = new DatabaseAdapterPass();
        $this->containerBuilder = new ContainerBuilder();

        // Create a mock manager definition
        $this->managerDefinition = new Definition();
        $this->containerBuilder->setDefinition('pro_backup.manager', $this->managerDefinition);
        $this->containerBuilder->setParameter('pro_backup.config', [
            'database' => [
                'enabled' => true,
                'connections' => [],
            ],
        ]);
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
        $mysqlDefinition = new Definition();
        $mysqlDefinition->addTag('pro_backup.database_adapter');

        $this->containerBuilder->setDefinition('pro_backup.database.mysql', $mysqlDefinition);

        $postgresDefinition = new Definition();
        $postgresDefinition->addTag('pro_backup.database_adapter');

        $this->containerBuilder->setDefinition('pro_backup.database.postgres', $postgresDefinition);

        // Process the container
        $this->compilerPass->process($this->containerBuilder);

        // Verify that method calls were added to the manager
        $methodCalls = $this->managerDefinition->getMethodCalls();
        $this->assertCount(2, $methodCalls);

        // Check the first method call
        $this->assertEquals('addAdapter', $methodCalls[0][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[0][1][0]);
        $this->assertEquals('pro_backup.database.mysql', (string) $methodCalls[0][1][0]);

        // Check the second method call
        $this->assertEquals('addAdapter', $methodCalls[1][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[1][1][0]);
        $this->assertEquals('pro_backup.database.postgres', (string) $methodCalls[1][1][0]);
    }

    public function testProcessWithMultipleTags(): void
    {
        // Create and register a service with multiple tags
        $mysqlDefinition = new Definition();
        $mysqlDefinition->addTag('pro_backup.database_adapter');
        $mysqlDefinition->addTag('pro_backup.database_adapter');

        $this->containerBuilder->setDefinition('pro_backup.database.mysql', $mysqlDefinition);

        // Process the container
        $this->compilerPass->process($this->containerBuilder);

        // Verify that only one method call was added to the manager
        // (since we're not iterating over tags, just service IDs)
        $methodCalls = $this->managerDefinition->getMethodCalls();
        $this->assertCount(1, $methodCalls);

        // Check the method call
        $this->assertEquals('addAdapter', $methodCalls[0][0]);
        $this->assertInstanceOf(Reference::class, $methodCalls[0][1][0]);
        $this->assertEquals('pro_backup.database.mysql', (string) $methodCalls[0][1][0]);
    }

    public function testProcessWithNoManagerDefinition(): void
    {
        // Create a new container without the manager definition
        $containerBuilder = new ContainerBuilder();

        // Create and register a tagged service
        $mysqlDefinition = new Definition();
        $mysqlDefinition->addTag('pro_backup.database_adapter');

        $containerBuilder->setDefinition('pro_backup.database.mysql', $mysqlDefinition);

        // Process the container
        $this->compilerPass->process($containerBuilder);

        // No exception should be thrown
        $this->assertTrue(true);
    }
}
