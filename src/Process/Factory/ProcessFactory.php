<?php

declare(strict_types=1);

namespace ProBackupBundle\Process\Factory;

use Symfony\Component\Process\Process;

class ProcessFactory
{
    public function createFromShellCommandline(string $command, ?string $cwd = null, ?array $env = null, mixed $input = null, ?float $timeout = 60): Process
    {
        return Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
    }
}
