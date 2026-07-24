# docker

| File | Applies to |
|---|---|
| `php/php.ini` | Every environment |
| `php/opcache.ini` | **Production only** |
| `php/xdebug.ini` | **Development only** |
| `php/php-fpm.conf` | Pool sizing, slow-request logging |
| `nginx/default.conf` | Local nginx; production uses the same directives |
| `entrypoint.sh` | Production container start |

---

## Image targets

`Dockerfile` builds four:

| Target | Contents |
|---|---|
| `base` | PHP 8.4 FPM + extensions, Europe/Istanbul |
| `vendor` | Composer install from lock file **only** |
| `dev` | base + Xdebug; source bind-mounted |
| `prod` | base + vendor + source, caches baked, runs as `www-data` |

The `vendor` stage exists so editing a PHP file does not invalidate the Composer
layer — a 5-second rebuild instead of 3 minutes.

---

## Two things that will bite you

**`opcache.validate_timestamps = 0`** in production means PHP never re-reads a
changed file. A deploy must replace the container; an in-place file edit is
silently ignored. There is no hotfix-by-SSH.

**`entrypoint.sh` does not run migrations.** Several containers start at once,
and concurrent `migrate` is a race that corrupts the schema. Migrations belong
to a single deploy job. See [../docs/deployment.md](../docs/deployment.md).

It does refuse to boot without `APP_KEY`, and waits up to 60 s for the database
so a container starting fractionally before Postgres does not crash-loop.

---

## Xdebug

Off by default (`start_with_request = trigger`) — step debugging is expensive.
Enable per invocation:

```bash
XDEBUG_TRIGGER=1 php artisan ...
```

---

## Local stack

`docker-compose.yml` runs PostgreSQL 17, Redis 7, OpenSearch 2, MinIO and
Mailpit at the versions production runs, plus separate `horizon` and `scheduler`
containers.

MinIO is there so the S3 driver is exercised locally rather than falling back to
the local disk and hiding S3-specific bugs until staging.

The local OpenSearch container runs with the security plugin **disabled**. The
production cluster does not.
