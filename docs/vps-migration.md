# Migrating to a VPS

Moving off shared hosting. **No business logic changes.** Everything that
differs is configuration: a real process manager instead of cron-driven
workers, and optionally Redis instead of the database for cache and queue.

---

## Why you would

| | cPanel | VPS |
|---|---|---|
| Queue latency | up to 5 minutes | under a second |
| Worker | bounded, started by cron | supervised daemon |
| Cache / sessions | database | Redis (optional) |
| Scheduler | cron every minute | the same, or systemd timer |
| PHP version | whatever the host offers | your choice |

The queue latency is usually what forces the move: a customer waiting five
minutes for a receipt notices.

---

## Before you start

**Do the cutover at your quietest hour**, and read the "one active worker"
warning below first. It is the only step that can cost real money if
rushed.

Provision the VPS with PHP 8.2+ (same extensions as `README.md`), MySQL or
MariaDB, Nginx or Apache, Composer, Node (for building assets), and
Supervisor or systemd.

---

## 1. Move the data

```bash
# On the old host
mysqldump -u USER -p DATABASE | gzip > petstore-db.sql.gz
tar czf petstore-storage.tar.gz storage/app/public

# On the new server
gunzip < petstore-db.sql.gz | mysql -u USER -p DATABASE
tar xzf petstore-storage.tar.gz
```

### Carry `APP_KEY` across unchanged

This is the step people get wrong.

`APP_KEY` decrypts the supplier credentials and the staff two-factor
secrets stored in the database. Generate a new key on the new server and
those values become unreadable — the store will authenticate against
nothing and every staff member with 2FA will be locked out.

Copy the existing `APP_KEY` value from the old `.env` into the new one.
**Do not run `php artisan key:generate` on the new server.**

If you have already done so and lost the old key, it is recoverable but
manual:

1. Re-enter the CJ credentials in Admin → Integrations.
2. Have every staff member re-enrol their authenticator app. An owner
   without 2FA can reset another user's; if everyone is locked out, clear
   `app_authentication_secret` for one owner directly in the database and
   let them re-enrol.

---

## 2. Set the application up

```bash
cd /var/www/petstore
composer install --no-dev --optimize-autoloader
npm ci && npm run build            # Node is a build tool, not a runtime need

cp /path/to/old/.env .env
nano .env                          # update APP_URL, DB_*, mail; KEEP APP_KEY

php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

```bash
chown -R www-data:www-data /var/www/petstore
chmod -R 775 storage bootstrap/cache
chmod 600 .env
```

---

## 3. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;

    # Only public/ is served. The rest of the application is not reachable.
    root /var/www/petstore/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    client_max_body_size 12M;   # room for image uploads

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;   # supplier calls can be slow
    }

    location ~ /\.(?!well-known) { deny all; }

    location ~* \.(css|js|jpg|jpeg|png|webp|avif|svg|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
}

server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

---

## 4. The cutover: exactly one active scheduler and worker

**This is the step that can cost money.**

While DNS propagates, both servers can be reachable. If both are running
the scheduler and a queue worker against the same database, both can pick
up the same fulfilment job and submit the same order to the supplier
twice.

The application defends against this — `withoutOverlapping`, a unique
`idempotency_key` on every fulfilment, and reconciliation by reference —
but those defences assume a shared cache and a single logical worker pool.
Two servers with separate caches weaken the first of them.

**Do this instead:**

1. On the **old** server, remove the scheduler and worker cron lines, or
   run `php artisan down`. Confirm in Admin → System → Health that both
   heartbeats stop updating.
2. Let the queue drain, or accept that pending jobs will run on the new
   server.
3. Take the final database and storage backup.
4. Restore onto the new server and confirm the site works by hosts-file
   override before touching DNS.
5. Start Supervisor on the new server.
6. Update DNS.
7. Keep the old server's workers off until DNS has fully propagated, then
   decommission it.

Never have both running at once, even briefly.

---

## 5. Supervisor

`/etc/supervisor/conf.d/petstore-worker.conf`:

```ini
[program:petstore-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/petstore/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
; Start with ONE worker. Supplier calls are rate limited to about one per
; second, so extra workers mostly queue behind the throttle. Add a second
; only if the queue genuinely backs up.
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/petstore/storage/logs/worker.log
stopwaitsecs=130    ; must exceed --timeout so a job is never killed mid-flight
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start petstore-worker:*
```

Remove the `petstore:work` cron line — it is for hosts without Supervisor
and would run a second, competing worker.

Keep the scheduler in cron:

```
* * * * * php /var/www/petstore/artisan schedule:run >> /dev/null 2>&1
```

### systemd instead

`/etc/systemd/system/petstore-worker.service`:

```ini
[Unit]
Description=Pet Store queue worker
After=network.target mysql.service

[Service]
User=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/petstore/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=120
TimeoutStopSec=130

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload && systemctl enable --now petstore-worker
```

---

## 6. Redis (optional)

Redis is optional throughout. Nothing requires it — that was a design
constraint so cPanel stays viable.

```bash
apt install redis-server php8.2-redis
composer require predis/predis   # or use the phpredis extension
```

```dotenv
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=your-strong-password
```

Bind Redis to localhost and set a password in `/etc/redis/redis.conf`:

```
bind 127.0.0.1 ::1
requirepass your-strong-password
maxmemory-policy noeviction
```

`noeviction` matters: the queue lives in Redis, and an eviction policy
that discards keys under pressure can silently drop queued jobs.

After switching, `php artisan config:cache` and restart the workers.

Note that switching the cache **clears the supplier access token cache and
all shipping quote caches**. Both regenerate on demand; no action needed.

---

## 7. After the cutover

- [ ] Storefront loads over HTTPS with a valid certificate
- [ ] Admin → System → Health shows both heartbeats healthy
- [ ] `php artisan cj:verify` passes
- [ ] Place one demo order end to end
- [ ] Webhook URLs updated at PayPal and Paystack to the new domain
- [ ] Send a test email and confirm delivery
- [ ] Nightly backups configured and one restore tested
- [ ] `https://your-domain.com/.env` returns 403 or 404
- [ ] Only one worker is running: `supervisorctl status`

### Rollback

Keep the old server intact for at least a week.

1. Point DNS back.
2. Stop the new server's workers.
3. Restore the final backup onto the old server if any orders were taken
   on the new one — those orders exist only in the new database, so export
   them before restoring anything over it.

The risky case is orders taken on the new server during a window you then
roll back. Check for orders created after the cutover before rolling back,
and migrate them by hand if there are any.
