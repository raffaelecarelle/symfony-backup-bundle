<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/vendor',
        __DIR__ . '/var/cache',
        \Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector::class,
        'tests/_fixtures'
    ])
    ->withImportNames(importShortClasses: false)
    ->withSets([
        \Rector\Set\ValueObject\LevelSetList::UP_TO_PHP_82,
        \Rector\Set\ValueObject\SetList::CODE_QUALITY,
        \Rector\Set\ValueObject\SetList::CODING_STYLE,
        \Rector\Set\ValueObject\SetList::DEAD_CODE,
        \Rector\Set\ValueObject\SetList::TYPE_DECLARATION,
    ])
    ->withPhpSets(php81: true);