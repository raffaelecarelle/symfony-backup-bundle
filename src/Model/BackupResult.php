<?php

namespace ProBackupBundle\Model;

/**
 * Result of a backup operation.
 */
class BackupResult
{
    /**
     * @var bool Whether the backup was successful
     */
    private bool $success;
    
    /**
     * @var string|null Path to the backup file
     */
    private ?string $filePath = null;
    
    /**
     * @var int|null Size of the backup file in bytes
     */
    private ?int $fileSize = null;
    
    /**
     * @var \DateTimeImmutable When the backup was created
     */
    private \DateTimeImmutable $createdAt;
    
    /**
     * @var float|null Duration of the backup operation in seconds
     */
    private ?float $duration = null;
    
    /**
     * @var string|null Error message if the backup failed
     */
    private ?string $error = null;
    
    /**
     * @var array Additional metadata
     */
    private array $metadata = [];
    
    /**
     * @var string|null Unique identifier for the backup
     */
    private ?string $id = null;

    /**
     * Constructor.
     *
     * @param bool $success Whether the backup was successful
     * @param string|null $filePath Path to the backup file
     * @param int|null $fileSize Size of the backup file in bytes
     * @param \DateTimeImmutable|null $createdAt When the backup was created
     * @param float|null $duration Duration of the backup operation in seconds
     * @param string|null $error Error message if the backup failed
     * @param array $metadata Additional metadata
     */
    public function __construct(
        bool $success = false,
        ?string $filePath = null,
        ?int $fileSize = null,
        ?\DateTimeImmutable $createdAt = null,
        ?float $duration = null,
        ?string $error = null,
        array $metadata = []
    ) {
        $this->success = $success;
        $this->filePath = $filePath;
        $this->fileSize = $fileSize;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->duration = $duration;
        $this->error = $error;
        $this->metadata = $metadata;
        $this->id = uniqid('backup_', true);
    }

    /**
     * Check if the backup was successful.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Set whether the backup was successful.
     *
     * @param bool $success
     * @return self
     */
    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    /**
     * Get the path to the backup file.
     *
     * @return string|null
     */
    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    /**
     * Set the path to the backup file.
     *
     * @param string|null $filePath
     * @return self
     */
    public function setFilePath(?string $filePath): self
    {
        $this->filePath = $filePath;
        return $this;
    }

    /**
     * Get the size of the backup file.
     *
     * @return int|null
     */
    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    /**
     * Set the size of the backup file.
     *
     * @param int|null $fileSize
     * @return self
     */
    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    /**
     * Get when the backup was created.
     *
     * @return \DateTimeImmutable
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Set when the backup was created.
     *
     * @param \DateTimeImmutable $createdAt
     * @return self
     */
    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Get the duration of the backup operation.
     *
     * @return float|null
     */
    public function getDuration(): ?float
    {
        return $this->duration;
    }

    /**
     * Set the duration of the backup operation.
     *
     * @param float|null $duration
     * @return self
     */
    public function setDuration(?float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    /**
     * Get the error message.
     *
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Set the error message.
     *
     * @param string|null $error
     * @return self
     */
    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    /**
     * Get the metadata.
     *
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Set the metadata.
     *
     * @param array $metadata
     * @return self
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Get a specific metadata value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Set a specific metadata value.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setMetadataValue(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Get the backup ID.
     *
     * @return string|null
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Set the backup ID.
     *
     * @param string|null $id
     * @return self
     */
    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }
}