<?php

namespace Symfony\Component\Backup\Tests\Adapter\Database;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Backup\Adapter\Database\MySQLAdapter;
use Symfony\Component\Backup\Model\BackupConfiguration;
use Symfony\Component\Backup\Model\BackupResult;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class MySQLAdapterTest extends TestCase
{
    private $mockConnection;
    private $mockLogger;
    private $adapter;
    private $tempDir;
    
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mysql_backup_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        
        $this->mockConnection = $this->createMock(Connection::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        
        // Configure mock connection
        $this->mockConnection->expects($this->any())
            ->method('getHost')
            ->willReturn('localhost');
            
        $this->mockConnection->expects($this->any())
            ->method('getUsername')
            ->willReturn('db_user');
            
        $this->mockConnection->expects($this->any())
            ->method('getPassword')
            ->willReturn('db_password');
            
        $this->mockConnection->expects($this->any())
            ->method('getDatabase')
            ->willReturn('test_db');
        
        $this->adapter = new MySQLAdapter($this->mockConnection, $this->mockLogger);
    }
    
    protected function tearDown(): void
    {
        // Clean up temp directory
        if (file_exists($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }
    
    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }
    
    public function testSupports(): void
    {
        $this->assertTrue($this->adapter->supports('mysql'));
        $this->assertFalse($this->adapter->supports('postgresql'));
        $this->assertFalse($this->adapter->supports('sqlite'));
    }
    
    public function testValidate(): void
    {
        $config = new BackupConfiguration();
        $config->setType('mysql');
        $config->setOptions([
            'single_transaction' => true,
            'routines' => true,
            'triggers' => true
        ]);
        
        $errors = $this->adapter->validate($config);
        
        $this->assertEmpty($errors);
    }
    
    public function testValidateWithInvalidOptions(): void
    {
        $config = new BackupConfiguration();
        $config->setType('mysql');
        $config->setOptions([
            'invalid_option' => true
        ]);
        
        $errors = $this->adapter->validate($config);
        
        $this->assertNotEmpty($errors);
    }
    
    public function testBackup(): void
    {
        // Create a mock for Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        
        // Create a mock for Filesystem
        $mockFilesystem = $this->getMockBuilder(Filesystem::class)
            ->getMock();
            
        $mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
            
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        // Replace the filesystem in the adapter
        $reflectionProperty = new \ReflectionProperty(MySQLAdapter::class, 'filesystem');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->adapter, $mockFilesystem);
        
        // Mock the filesize function
        $this->mockFunction('filesize', function() {
            return 1024;
        });
        
        // Create the configuration
        $config = new BackupConfiguration();
        $config->setType('mysql');
        $config->setName('test_backup');
        $config->setOutputPath($this->tempDir);
        $config->setOptions([
            'single_transaction' => true,
            'routines' => true,
            'triggers' => true
        ]);
        
        // Execute the backup
        $result = $this->adapter->backup($config);
        
        // Assertions
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1024, $result->getFileSize());
        $this->assertStringContainsString('test_backup', $result->getFilePath());
    }
    
    public function testBackupFailure(): void
    {
        // Create a mock for Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);
            
        $mockProcess->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn('Error: Access denied');
        
        // Create a mock for Filesystem
        $mockFilesystem = $this->getMockBuilder(Filesystem::class)
            ->getMock();
            
        $mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
            
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        // Replace the filesystem in the adapter
        $reflectionProperty = new \ReflectionProperty(MySQLAdapter::class, 'filesystem');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->adapter, $mockFilesystem);
        
        // Create the configuration
        $config = new BackupConfiguration();
        $config->setType('mysql');
        $config->setName('test_backup');
        $config->setOutputPath($this->tempDir);
        
        // Execute the backup
        $result = $this->adapter->backup($config);
        
        // Assertions
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Error: Access denied', $result->getError());
    }
    
    public function testRestore(): void
    {
        // Create a test backup file
        $backupPath = $this->tempDir . '/test_backup.sql';
        file_put_contents($backupPath, 'test backup content');
        
        // Create a mock for Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        
        // Create a mock for Filesystem
        $mockFilesystem = $this->getMockBuilder(Filesystem::class)
            ->getMock();
            
        $mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
            
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        // Replace the filesystem in the adapter
        $reflectionProperty = new \ReflectionProperty(MySQLAdapter::class, 'filesystem');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->adapter, $mockFilesystem);
        
        // Mock the decompressIfNeeded method
        $this->mockMethod($this->adapter, 'decompressIfNeeded', function($path) {
            return $path;
        });
        
        // Execute the restore
        $result = $this->adapter->restore($backupPath);
        
        // Assertions
        $this->assertTrue($result);
    }
    
    public function testRestoreFailure(): void
    {
        // Create a test backup file
        $backupPath = $this->tempDir . '/test_backup.sql';
        file_put_contents($backupPath, 'test backup content');
        
        // Create a mock for Process
        $mockProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $mockProcess->expects($this->once())
            ->method('run');
            
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);
            
        $mockProcess->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn('Error: Access denied');
        
        // Create a mock for Filesystem
        $mockFilesystem = $this->getMockBuilder(Filesystem::class)
            ->getMock();
            
        $mockFilesystem->expects($this->any())
            ->method('exists')
            ->willReturn(true);
            
        // Replace the Process::fromShellCommandline static method
        $this->mockStaticMethod(Process::class, 'fromShellCommandline', function() use ($mockProcess) {
            return $mockProcess;
        });
        
        // Replace the filesystem in the adapter
        $reflectionProperty = new \ReflectionProperty(MySQLAdapter::class, 'filesystem');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->adapter, $mockFilesystem);
        
        // Mock the decompressIfNeeded method
        $this->mockMethod($this->adapter, 'decompressIfNeeded', function($path) {
            return $path;
        });
        
        // Execute the restore
        $result = $this->adapter->restore($backupPath);
        
        // Assertions
        $this->assertFalse($result);
    }
    
    /**
     * Helper method to mock a static method
     */
    private function mockStaticMethod($class, $method, $callback)
    {
        $mock = \Closure::bind(function($class, $method, $callback) {
            $class::$method = $callback;
            return function() use($class, $method) {
                $class::$method = null;
            };
        }, null, $class);
        
        return $mock($class, $method, $callback);
    }
    
    /**
     * Helper method to mock a method in an object
     */
    private function mockMethod($object, $method, $callback)
    {
        $reflectionMethod = new \ReflectionMethod(get_class($object), $method);
        $reflectionMethod->setAccessible(true);
        
        $closure = \Closure::bind(function($object, $method, $callback) {
            $object->$method = $callback;
        }, null, $object);
        
        $closure($object, $method, $callback);
    }
    
    /**
     * Helper method to mock a global function
     */
    private function mockFunction($name, $callback)
    {
        $namespace = 'Symfony\\Component\\Backup\\Adapter\\Database';

        if (!function_exists($namespace . '\\' . $name)) {
            eval("namespace $namespace; function $name() { return call_user_func_array(\\$name, func_get_args()); }");
        }

        $mock = \Closure::bind(function($namespace, $name, $callback) {
            $GLOBALS['__phpunit_function_mock_' . $namespace . '_' . $name] = $callback;
            return function() use($namespace, $name) {
                unset($GLOBALS['__phpunit_function_mock_' . $namespace . '_' . $name]);
            };
        }, null, null);

        return $mock($namespace, $name, $callback);
    }
}