<?php

declare(strict_types=1);

/**
 * One-command local setup, for trying the application out.
 *
 * Creates a .env pointed at SQLite, generates an application key, migrates,
 * seeds the demo catalogue and links storage. It is deliberately NOT a
 * deployment script: it chooses SQLite and it seeds sample products, so it
 * refuses to touch anything that looks like a production install.
 *
 * Run with: composer run setup:local
 */

$root = dirname(__DIR__);
$env = $root.'/.env';
$example = $root.'/.env.example';

function step(string $message): void
{
    echo "\033[36m›\033[0m {$message}\n";
}

function fail(string $message): never
{
    echo "\033[31m✗ {$message}\033[0m\n";
    exit(1);
}

/* Refuse to run against anything that looks live. */
if (file_exists($env)) {
    $current = file_get_contents($env) ?: '';

    if (preg_match('/^APP_ENV\s*=\s*production/mi', $current)) {
        fail('This .env says APP_ENV=production. Refusing to touch it. See docs/deployment-cpanel.md for deploying.');
    }

    step('.env already exists, leaving it alone.');
} else {
    if (! file_exists($example)) {
        fail('.env.example is missing.');
    }

    step('Creating .env from .env.example, pointed at SQLite.');

    $contents = file_get_contents($example) ?: '';

    $replacements = [
        '/^APP_ENV=.*/m' => 'APP_ENV=local',
        '/^APP_DEBUG=.*/m' => 'APP_DEBUG=true',
        '/^APP_URL=.*/m' => 'APP_URL=http://127.0.0.1:8000',
        '/^DB_CONNECTION=.*/m' => 'DB_CONNECTION=sqlite',
        '/^DB_HOST=.*/m' => '# DB_HOST=127.0.0.1',
        '/^DB_PORT=.*/m' => '# DB_PORT=3306',
        '/^DB_DATABASE=.*/m' => '# DB_DATABASE=petstore',
        '/^DB_USERNAME=.*/m' => '# DB_USERNAME=',
        '/^DB_PASSWORD=.*/m' => '# DB_PASSWORD=',
        // Log mail to storage/logs rather than needing an SMTP server.
        '/^MAIL_MAILER=.*/m' => 'MAIL_MAILER=log',
        '/^SESSION_SECURE_COOKIE=.*/m' => 'SESSION_SECURE_COOKIE=false',
    ];

    foreach ($replacements as $pattern => $replacement) {
        $contents = preg_replace($pattern, $replacement, $contents) ?? $contents;
    }

    file_put_contents($env, $contents);
}

$database = $root.'/database/database.sqlite';

if (! file_exists($database)) {
    step('Creating database/database.sqlite.');
    touch($database);
}

$commands = [
    'Generating the application key' => 'key:generate --force',
    'Running migrations' => 'migrate:fresh --force',
    'Seeding the demo catalogue' => 'db:seed --class=DemoSeeder --force',
    // --force so re-running the setup does not print a red error about
    // a link that is already there.
    'Linking storage' => 'storage:link --force',
];

foreach ($commands as $label => $command) {
    step($label.'.');

    passthru(sprintf('%s artisan %s', escapeshellarg(PHP_BINARY), $command), $status);

    if ($status !== 0) {
        fail("`php artisan {$command}` failed.");
    }
}

echo <<<'TEXT'

  Ready.

  Two things left:

    npm ci && npm run build            build the storefront assets
    php artisan petstore:make-admin    create your admin login

  Then:

    php artisan serve

  Storefront  http://127.0.0.1:8000
  Admin       http://127.0.0.1:8000/admin

  The supplier and payments are in demo mode, so nothing is charged and no
  supplier order is placed. A banner on every page says so.

  Demo failure scenarios are in docs/demo-scenarios.md.

TEXT;
