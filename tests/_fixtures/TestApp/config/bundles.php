<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    ProBackupBundle\ProBackupBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['test' => true, 'no_prof' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['test' => true, 'no_prof' => true],
];
