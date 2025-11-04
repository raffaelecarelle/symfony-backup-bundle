<?php

declare(strict_types=1);

namespace ProBackupBundle\Adapter;

use Doctrine\DBAL\Connection;

interface DatabaseConnectionInterface
{
    public function getConnection(): Connection;
}
