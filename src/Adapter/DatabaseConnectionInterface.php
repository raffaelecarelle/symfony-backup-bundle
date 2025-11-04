<?php

namespace ProBackupBundle\Adapter;

use Doctrine\DBAL\Connection;

interface DatabaseConnectionInterface
{
    public function getConnection(): Connection;
}