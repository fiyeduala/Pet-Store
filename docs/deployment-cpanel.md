# Deploying to cPanel

A safe deployment for shared hosting, where you cannot run a persistent
process manager.

---

## 1. Check the host can actually do this

Work through this list **before** uploading anything. If something is
missing, that is a genuine blocker — several have no safe workaround, and
pretending otherwise produces a store that appears to work and quietly
does not.

| Requirement | How to check | If missing |
|---|---|---|
| PHP 8.2+ for **both** web and CLI | cPanel → MultiPHP Manager, and `php -v` over SSH | Blocker. Ask the host to upgrade. |
| Required extensions | `php -m` over SSH, or cPanel → Select PHP Version → Extensions | Blocker. Most are toggles in cPanel. |
| Composer | `composer --version` | Install per-account, or build the vendor directory locally and upload it. |
| MySQL 8 / MariaDB 10.6+ | cPanel → MySQL Databases | Older versions may reject some column types. |
| HTTPS certificate | cPanel → SSL/TLS Status | Blocker. Payments and webhooks require it. |
| Cron | cPanel → Cron Jobs | Blocker. Without it, no stock sync, no tracking, no queued email. |
| Outbound HTTPS | `curl -I https://api-m.paypal.com` over SSH | Blocker. Many shared hosts firewall outbound by default; ask support to open it. |
| Document root can point at a subdirectory | cPanel → Domains | Blocker unless you use the `public_html` symlink approach below. |
| SMTP | Send a test with cPanel's mail tools | Blocker for receipts and tracking links. |

The two most commonly missing are **outbound HTTPS** and a **CLI PHP that
matches the web PHP**. Check both explicitly; assuming them costs a day.

---

## 2. Lay the files out safely

**Never put the whole application under `public_html`.** Doing so exposes
`.env`, your database credentials, `storage/` and the entire source tree
to anyone who guesses a URL.

The layout:

```
/home/USER/
├── petstore/              ← the application. NOT web-accessible.
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/            ← only this should be reachable
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   ├── vendor/
│   └── .env               ← outside the web root
└── public_html/           ← the document root
```

### Option A — point the document root at `public/` (preferred)

In cPanel → Domains, set the domain's document root to
`/home/USER/petstore/public`. Nothing else is reachable. Done.

### Option B — when the host will not let you change the document root

Put the contents of `public/` into `public_html/` and edit
`public_html/index.php` so its two require paths point up and across:

```php
require __DIR__.'/../petstore/vendor/autoload.php';
$app = require_once __DIR__.'/../petstore/bootstrap/app.php';
```

Then add this to `public_html/.htaccess`, above the Laravel rules, as a
second line of defence:

```apache
# Refuse dotfiles and anything that should never be served.
<FilesMatch "^\.|composer\.(json|lock)$|\.env">
    Require all denied
</FilesMatch>
Options -Indexes
```

Verify by requesting `https://your-domain.com/.env` — you must get 403 or
404, never a file.

---

## 3. Upload and install

Over SSH, from `/home/USER/petstore`:

```bash
composer install --no-dev --optimize-autoloader

cp .env.example .env
nano .env                       # fill in everything; see the file's comments
php artisan key:generate
```

**Keep `APP_KEY` safe.** It decrypts your stored supplier credentials.
Lose it and they become unreadable — recoverable (re-enter them) but
annoying. Change it on a live site without re-entering them and they fail
silently.

Then:

```bash
php artisan migrate --force
php artisan db:seed --force      # settings, roles, market, taxonomy, policy drafts
php artisan storage:link
php artisan petstore:make-admin --role=owner
```

Do **not** run `DemoSeeder` in production. It refuses to run when
`APP_ENV=production` unless you deliberately set `DEMO_SEEDING_ENABLED`,
and there is no good reason to.

### Assets

Vite needs Node, which shared hosts usually lack — and you should not need
Node at runtime anyway. **Build locally and upload the result:**

```bash
# On your own machine
npm ci
npm run build
# Upload the whole public/build directory
```

`public/build/manifest.json` and the hashed files under
`public/build/assets/` are all that is required. If `@vite` throws
"Unable to locate file in Vite manifest", the build directory did not
upload.

### Permissions

```bash
chmod -R 755 storage bootstrap/cache
find storage bootstrap/cache -type f -exec chmod 644 {} \;
chmod 600 .env
```

### Cache for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Re-run all four after **any** `.env` change. A cached config ignores
`.env` entirely, which is a classic cause of "I changed it and nothing
happened".

---

## 4. Cron

cPanel → Cron Jobs. Use the absolute path to the correct PHP binary — the
default `php` in cron is often an older version than the one you selected
in MultiPHP Manager. Find it with `which php` or ask the host.

### The scheduler (required)

```
* * * * * /usr/local/bin/ea-php82 /home/USER/petstore/artisan schedule:run >> /dev/null 2>&1
```

If the host only allows five-minute resolution, that is acceptable; the
schedule has nothing finer than five minutes.

### The queue worker (required)

Shared hosting cannot run `queue:work` as a daemon — it would be killed,
or would breach the process limit. Use the bounded worker, which processes
what is waiting and then exits:

```
*/5 * * * * /usr/local/bin/ea-php82 /home/USER/petstore/artisan petstore:work --max-seconds=55 --max-jobs=50 >> /dev/null 2>&1
```

`petstore:work` holds a cache lock, so if one run overruns the next exits
immediately instead of stacking a second worker.

**What this costs you.** Queued work waits up to five minutes. In practice:

| Job | Typical delay |
|---|---|
| Order confirmation email | up to 5 minutes |
| Guest tracking link email | up to 5 minutes |
| Stock sync | hourly, plus up to 5 minutes |
| Tracking refresh | every 30 minutes, plus up to 5 minutes |

If a five-minute wait for a receipt is unacceptable, that is a reason to
move to a VPS (see `docs/vps-migration.md`), not a reason to run the
worker inline in a web request.

### Never expose cron over HTTP

Do not add a route that runs the scheduler. A publicly reachable endpoint
that triggers background work is a denial-of-service lever and, with
supplier submission attached to it, potentially an expensive one.

### Confirm it is running

Admin → System → Health shows a heartbeat for the scheduler and the queue
worker. Both should read "Healthy" within ten minutes of setting up cron.
If they say "Never run", cron is not working — check the PHP path first.

---

## 5. Webhooks

Register these with each provider once HTTPS is live:

| Provider | URL |
|---|---|
| PayPal | `https://your-domain.com/webhooks/payments/paypal` |
| Paystack | `https://your-domain.com/webhooks/payments/paystack` |

They are CSRF-exempt by design; authenticity comes from provider signature
verification, not session state. An event that fails verification is
recorded and ignored, never applied.

---

## 6. Backups

Set up before launch, not after the first problem.

**Database.** cPanel → Backup, or a nightly cron:

```
0 2 * * * /usr/bin/mysqldump -u USER -p'PASSWORD' DATABASE | gzip > /home/USER/backups/db-$(date +\%F).sql.gz
```

Put the password in `~/.my.cnf` with `chmod 600` rather than in the crontab,
where it is visible to anything that can read your crontab.

**Files.** `storage/app/public` holds uploaded images. Back it up with the
database.

**Keep `APP_KEY`.** Store it somewhere other than the server. A database
backup without it cannot decrypt the supplier credentials inside it.

### Restoring

```bash
gunzip < db-2026-09-21.sql.gz | mysql -u USER -p DATABASE
# restore storage/app/public
php artisan optimize:clear
php artisan config:cache
```

Test a restore into a staging database at least once. An untested backup
is a hope, not a backup.

---

## 7. Deploying an update

```bash
cd /home/USER/petstore
php artisan down --render="errors::503"

git pull                                    # or upload the files
composer install --no-dev --optimize-autoloader
php artisan migrate --force
# upload the freshly built public/build

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan up
```

Take a database backup before any deployment that includes a migration.

---

## 8. Troubleshooting

| Symptom | Usual cause |
|---|---|
| 500 with a blank page | `storage/` not writable, or a stale cached config. Check `storage/logs/laravel.log`. |
| "Unable to locate file in Vite manifest" | `public/build` was not uploaded. |
| Changed `.env`, nothing happened | Cached config. Re-run `php artisan config:cache`. |
| Health page says the scheduler never ran | Wrong PHP binary in the cron line. |
| Supplier calls all time out | Outbound HTTPS is firewalled. Ask the host. |
| Emails never arrive | SMTP credentials, or the queue worker is not running. Check Health. |
| Images 404 | `php artisan storage:link` was not run, or symlinks are disabled — copy the files instead. |
