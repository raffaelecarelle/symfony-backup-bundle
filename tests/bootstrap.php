<?php

declare(strict_types=1);

// Composer autoload
$autoload = __DIR__.'/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(\STDERR, "Cannot find vendor/autoload.php at $autoload\n");
    exit(1);
}
require $autoload;

use Symfony\Component\Dotenv\Dotenv;

// Ensure APP_ENV is test by default
if (!isset($_SERVER['APP_ENV']) && !isset($_ENV['APP_ENV'])) {
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
}

$dotenv = (new Dotenv())->usePutenv();

$distFile = __DIR__.'/_fixtures/TestApp/.env.test.dist';
$localFile = __DIR__.'/_fixtures/TestApp/.env.test.local';

// Load defaults first (does not override already-set env vars)
if (is_file($distFile)) {
    $dotenv->load($distFile);
}

// In local dev, allow overriding defaults via .env.test.local
// Avoid overriding CI-provided env vars on GitHub Actions
$isCI = filter_var(getenv('GITHUB_ACTIONS') ?: 'false', \FILTER_VALIDATE_BOOLEAN);
if (!$isCI && is_file($localFile)) {
    // Overwrite defaults loaded from dist for local development convenience
    $dotenv->overload($localFile);
} elseif (is_file($localFile)) {
    // On CI, read local values only for missing vars (no override)
    $dotenv->load($localFile);
}
