<?php

declare(strict_types=1);

namespace ProBackupBundle\Process;

use ProBackupBundle\Process\Factory\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessFailedException;

trait ProcessTrait
{
    private ?ProcessFactory $processFactory;

    private function executeCommand(string $command): bool
    {
        if (null === $this->processFactory) {
            $this->processFactory = new ProcessFactory();
        }

        $process = $this->processFactory->createFromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout
        $process->run();

        if (!$process->isSuccessful()) {
            dump($process->getErrorOutput());
            throw new ProcessFailedException($process);
        }

        return $process->isSuccessful();
    }
}
