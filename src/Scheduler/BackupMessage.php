<?php

declare(strict_types=1);

namespace ProBackupBundle\Scheduler;

use ProBackupBundle\Model\BackupConfiguration;

/**
 * Message for scheduled backups.
 */
class BackupMessage
{
    private ?string $name = null;

    private ?string $storage = null;

    private ?string $compression = null;

    private ?string $outputPath = null;

    private array $exclusions = [];

    /**
     * Constructor.
     *
     * @param string $type The backup type (database|filesystem)
     */
    public function __construct(private readonly string $type)
    {
    }

    /**
     * Get the backup type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the backup name.
     */
    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get the backup name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set the storage adapter.
     */
    public function setStorage(?string $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    /**
     * Get the storage adapter.
     */
    public function getStorage(): ?string
    {
        return $this->storage;
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
     * Get the compression type.
     */
    public function getCompression(): ?string
    {
        return $this->compression;
    }

    /**
     * Set the output path.
     */
    public function setOutputPath(?string $outputPath): self
    {
        $this->outputPath = $outputPath;

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
     * Set the exclusions.
     */
    public function setExclusions(array $exclusions): self
    {
        $this->exclusions = $exclusions;

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
     * Create a BackupConfiguration from this message.
     */
    public function toConfiguration(): BackupConfiguration
    {
        $config = new BackupConfiguration();
        $config->setType($this->type);

        if ($this->name) {
            $config->setName($this->name);
        } else {
            $config->setName(\sprintf('%s_%s', $this->type, date('Y-m-d_H-i-s')));
        }

        if ($this->storage) {
            $config->setStorage($this->storage);
        }

        if ($this->compression) {
            $config->setCompression($this->compression);
        }

        if ($this->outputPath) {
            $config->setOutputPath($this->outputPath);
        }

        if ([] !== $this->exclusions) {
            $config->setExclusions($this->exclusions);
        }

        return $config;
    }
}
