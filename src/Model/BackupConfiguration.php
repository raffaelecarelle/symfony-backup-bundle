<?php

declare(strict_types=1);

namespace ProBackupBundle\Model;

/**
 * Configuration for backup operations.
 */
class BackupConfiguration
{
    /**
     * @var string Type of backup ('database' or 'filesystem')
     */
    private string $type = '';

    /**
     * @var string Name of the backup
     */
    private string $name = '';

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
     * @var string|null Output path for the backup file
     */
    private ?string $outputPath = null;

    /**
     * Constructor.
     */
    public function __construct(string $type = '', string $name = '')
    {
        if (!empty($type)) {
            $this->type = $type;
        }

        if (!empty($name)) {
            $this->name = $name;
        }
    }

    /**
     * Get the backup type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the backup type.
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get the backup name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the backup name.
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get the backup options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set the backup options.
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Get a specific option.
     */
    public function getOption(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set a specific option.
     */
    public function setOption(string $key, $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Get the storage adapter.
     */
    public function getStorage(): string
    {
        return $this->storage;
    }

    /**
     * Set the storage adapter.
     */
    public function setStorage(string $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    /**
     * Get the compression type.
     */
    public function getCompression(): ?string
    {
        return $this->compression;
    }

    /**
     * Set the compression type.
     */
    public function setCompression(?string $compression): self
    {
        $this->compression = $compression;

        return $this;
    }

    /**
     * Get the exclusions.
     */
    public function getExclusions(): array
    {
        return $this->exclusions;
    }

    /**
     * Set the exclusions.
     */
    public function setExclusions(array $exclusions): self
    {
        $this->exclusions = $exclusions;

        return $this;
    }

    /**
     * Add an exclusion.
     */
    public function addExclusion(string $exclusion): self
    {
        $this->exclusions[] = $exclusion;

        return $this;
    }

    /**
     * Get the output path.
     */
    public function getOutputPath(): ?string
    {
        return $this->outputPath;
    }

    /**
     * Set the output path.
     */
    public function setOutputPath(string $outputPath): self
    {
        $this->outputPath = $outputPath;

        return $this;
    }
}
