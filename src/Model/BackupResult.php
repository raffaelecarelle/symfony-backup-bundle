<?php

declare(strict_types=1);

namespace ProBackupBundle\Model;

/**
 * Result of a backup operation.
 */
class BackupResult
{
    /**
     * @var string|null Unique identifier for the backup
     */
    private ?string $id = null;

    /**
     * Constructor.
     *
     * @param bool                    $success   Whether the backup was successful
     * @param string|null             $filePath  Path to the backup file
     * @param int|null                $fileSize  Size of the backup file in bytes
     * @param \DateTimeImmutable|null $createdAt When the backup was created
     * @param float|null              $duration  Duration of the backup operation in seconds
     * @param string|null             $error     Error message if the backup failed
     * @param array                   $metadata  Additional metadata
     */
    public function __construct(
        private bool $success = false,
        private ?string $filePath = null,
        private ?int $fileSize = null,
        private ?\DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        private ?float $duration = null,
        private ?string $error = null,
        private array $metadata = [],
    ) {
        $this->id = uniqid('backup_', true);
    }

    /**
     * Check if the backup was successful.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Set whether the backup was successful.
     */
    public function setSuccess(bool $success): self
    {
        $this->success = $success;

        return $this;
    }

    /**
     * Get the path to the backup file.
     */
    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    /**
     * Set the path to the backup file.
     */
    public function setFilePath(?string $filePath): self
    {
        $this->filePath = $filePath;

        return $this;
    }

    /**
     * Get the size of the backup file.
     */
    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    /**
     * Alias for getFileSize() for backward compatibility.
     */
    public function getSize(): ?int
    {
        return $this->getFileSize();
    }

    /**
     * Set the size of the backup file.
     */
    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    /**
     * Get when the backup was created.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Set when the backup was created.
     */
    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Get the duration of the backup operation.
     */
    public function getDuration(): ?float
    {
        return $this->duration;
    }

    /**
     * Set the duration of the backup operation.
     */
    public function setDuration(?float $duration): self
    {
        $this->duration = $duration;

        return $this;
    }

    /**
     * Get the error message.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Set the error message.
     */
    public function setError(?string $error): self
    {
        $this->error = $error;

        return $this;
    }

    /**
     * Get the metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Set the metadata.
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Get a specific metadata value.
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Set a specific metadata value.
     */
    public function setMetadataValue(string $key, $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Get the backup ID.
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Set the backup ID.
     */
    public function setId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }
}
