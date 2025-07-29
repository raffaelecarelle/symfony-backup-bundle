<?php

namespace Symfony\Component\Backup\Model;

/**
 * Configuration for backup operations.
 */
class BackupConfiguration
{
    /**
     * @var string Type of backup ('database' or 'filesystem')
     */
    private string $type;
    
    /**
     * @var string Name of the backup
     */
    private string $name;
    
    /**
     * @var array Additional options for the backup
     */
    private array $options = [];
    
    /**
     * @var string Storage adapter to use
     */
    private string $storage = 'local';
    
    /**
     * @var string|null Compression type to use
     */
    private ?string $compression = null;
    
    /**
     * @var array Tables or paths to exclude
     */
    private array $exclusions = [];
    
    /**
     * @var string Output path for the backup file
     */
    private string $outputPath;

    /**
     * Get the backup type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the backup type.
     *
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get the backup name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the backup name.
     *
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get the backup options.
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set the backup options.
     *
     * @param array $options
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Get a specific option.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getOption(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set a specific option.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setOption(string $key, $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * Get the storage adapter.
     *
     * @return string
     */
    public function getStorage(): string
    {
        return $this->storage;
    }

    /**
     * Set the storage adapter.
     *
     * @param string $storage
     * @return self
     */
    public function setStorage(string $storage): self
    {
        $this->storage = $storage;
        return $this;
    }

    /**
     * Get the compression type.
     *
     * @return string|null
     */
    public function getCompression(): ?string
    {
        return $this->compression;
    }

    /**
     * Set the compression type.
     *
     * @param string|null $compression
     * @return self
     */
    public function setCompression(?string $compression): self
    {
        $this->compression = $compression;
        return $this;
    }

    /**
     * Get the exclusions.
     *
     * @return array
     */
    public function getExclusions(): array
    {
        return $this->exclusions;
    }

    /**
     * Set the exclusions.
     *
     * @param array $exclusions
     * @return self
     */
    public function setExclusions(array $exclusions): self
    {
        $this->exclusions = $exclusions;
        return $this;
    }

    /**
     * Add an exclusion.
     *
     * @param string $exclusion
     * @return self
     */
    public function addExclusion(string $exclusion): self
    {
        $this->exclusions[] = $exclusion;
        return $this;
    }

    /**
     * Get the output path.
     *
     * @return string
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    /**
     * Set the output path.
     *
     * @param string $outputPath
     * @return self
     */
    public function setOutputPath(string $outputPath): self
    {
        $this->outputPath = $outputPath;
        return $this;
    }
}