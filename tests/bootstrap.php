<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Laravel resolves env() through a repository that reads $_SERVER before
 * $_ENV. PHPUnit's <env> entries populate $_ENV and putenv() but not
 * $_SERVER, so on any machine whose shell exports APP_ENV (CI images and
 * containers commonly do) the host value silently wins and the suite runs
 * against the development environment — including its database.
 *
 * Copying PHPUnit's values into $_SERVER before the framework boots makes
 * the declared test environment authoritative wherever the suite runs.
 */
require __DIR__.'/../vendor/autoload.php';

$forced = [
    'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'APP_NAME', 'APP_MAINTENANCE_DRIVER',
    'DB_CONNECTION', 'DB_DATABASE', 'DB_URL',
    'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'MAIL_MAILER',
    'BCRYPT_ROUNDS', 'TELESCOPE_ENABLED', 'PULSE_ENABLED',
];

foreach ($forced as $key) {
    if (array_key_exists($key, $_ENV)) {
        $_SERVER[$key] = $_ENV[$key];
    }
}

// Belt and braces: a stray .env.testing lookup must not fall back to .env
// because the shell happened to export something else.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] ?? 'testing';
putenv('APP_ENV='.$_SERVER['APP_ENV']);
