<?php

declare(strict_types=1);

namespace ProBackupBundle\Model;

/**
 * Represents a scheduled backup entry.
 */
class BackupSchedule
{
    private ?BackupConfiguration $configuration = null;

    /**
     * Frequency keyword: daily|weekly|monthly.
     */
    private string $frequency = 'daily';

    private ?\DateTimeImmutable $nextRun = null;

    private bool $enabled = true;

    public function getConfiguration(): ?BackupConfiguration
    {
        return $this->configuration;
    }

    public function setConfiguration(BackupConfiguration $configuration): self
    {
        $this->configuration = $configuration;

        return $this;
    }

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    public function setFrequency(string $frequency): self
    {
        $this->frequency = $frequency;

        return $this;
    }

    public function getNextRun(): ?\DateTimeImmutable
    {
        return $this->nextRun;
    }

    public function setNextRun(\DateTimeImmutable $nextRun): self
    {
        $this->nextRun = $nextRun;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}
