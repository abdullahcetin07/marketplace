# Deployment

---

## Image

`Dockerfile` has four targets. Production builds `prod`:

```bash
docker build --target prod -t marketplaceos:$GIT_SHA .
```

The `vendor` stage installs Composer dependencies from `composer.json` and
`composer.lock` **only**, so editing a PHP file does not invalidate that layer —
the difference between a 5-second and a 3-minute rebuild.

The `prod` target bakes in `config:cache`, `route:cache` and `event:cache` at
**build** time. Two reasons: no container pays the cost at first request, and a
broken config fails the *build* rather than the first user.

It runs as `www-data`. Xdebug exists only in `dev`.

CI builds the prod image on every push (`.github/workflows/ci.yml`) — a
Dockerfile that only gets built at deploy time is a Dockerfile that breaks at
deploy time.

---

## Migrations do not run in the entrypoint

`docker/entrypoint.sh` deliberately does **not** run `migrate`.

Several containers start simultaneously. Concurrent `migrate` is a race that
eventually corrupts the schema — two processes both seeing a migration as
pending, both running it.

Migrations belong to a **single deploy job**, run once, before the new
containers take traffic:

```bash
php artisan migrate --force
php artisan marketplace:sync-permissions
php artisan db:seed --class=RolePermissionSeeder --force
```

All three are idempotent.

---

## Deploy sequence

1. Build and push the image, tagged with the commit SHA.
2. **Run the migration job** (single instance, to completion).
3. Roll out the new web containers.
4. Roll out the new Horizon containers.
5. `php artisan horizon:terminate`.

**Step 5 is not optional.** Without it, workers keep executing the previous
release's code against the new schema — the most common source of
post-deploy data corruption.

**Migrations must be backwards-compatible with the running release**, because
between steps 2 and 3 the old code is live against the new schema. Adding a NOT
NULL column without a default breaks production in that window. Use the
expand-then-contract pattern: add nullable, backfill, deploy code, make NOT NULL
in a later migration.

---

## OPcache and restarts

`validate_timestamps=0` means PHP never re-reads a changed file. A deploy
**must** replace containers — an in-place file swap is silently ignored.

That is the correct trade for immutable-image deployment, but it is a real
constraint: there is no such thing as a hotfix by editing a file on the server.

---

## Environment

`.env` is never in the image. Inject via the orchestrator's secret mechanism.

The entrypoint refuses to boot without `APP_KEY` rather than starting in an
unencryptable state.

Production overrides worth stating explicitly:

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=stderr              # container log collection
SESSION_SECURE_COOKIE=true
OPENSEARCH_SSL_VERIFY=true      # must be true — see docs/search.md
TRUSTED_PROXIES=10.0.0.0/8      # the balancer's CIDR, not '*'
```

`APP_KEY` rotation invalidates every encrypted column — 2FA secrets and recovery
codes. Use `APP_PREVIOUS_KEYS` to decrypt during a rotation window.

### PayTR — one setting lives in THEIR panel, not in this repository

`PAYTR_MERCHANT_ID`, `PAYTR_MERCHANT_KEY`, `PAYTR_MERCHANT_SALT` and
`PAYTR_TEST_MODE` come from the environment like everything else. **The
notification URL does not** — the iFrame API has no parameter for it (the
get-token request takes `merchant_ok_url` and `merchant_fail_url`, which are only
where the BROWSER is sent afterwards). It is a merchant-panel setting:

> **PayTR Mağaza Paneli → Destek & Kurulum → Ayarlar → Bildirim URL**

and it must be set to this application's callback route, per environment:

```
https://<host>/api/v1/payments/paytr/callback
```

**GETTING THIS WRONG LOOKS EXACTLY LIKE THE INTEGRATION BEING BROKEN, and it is
silent on our side.** A payment succeeds, the buyer is redirected to the success
page, the money is really taken — and the order stays `awaiting_payment` forever,
because the callback is what settles it (Payment.md §3: the redirect is not the
source of truth). PayTR retries the notification roughly once a minute until it
receives `"OK"`, so the symptom in **nginx**, not in the application log, is a
stream of POSTs to whatever wrong path is configured:

```bash
grep -c "payments/paytr/callback" /var/log/nginx/*access.log   # should be > 0
awk '$1 == "212.252.97.250"' /var/log/nginx/*access.log | tail # PayTR's own IP
```

A 404 there means the panel points somewhere else; a 419 would mean the CSRF
exemption in `bootstrap/app.php` no longer matches the route (there is a test
pinning that — `tests/Feature/Payment/CallbackCsrfTest.php`).

Because PayTR keeps retrying, correcting the panel URL settles the outstanding
payments by itself. If the retries have already stopped, the notification can be
re-sent per transaction from the panel.

---

## Health checks

| Endpoint | Purpose |
|---|---|
| `/up` | Laravel's health route — liveness |
| `/api/v1/health` | Application readiness, returns version and time |
| `/fpm-ping` | PHP-FPM liveness |
| `/fpm-status` | FPM pool metrics |

The container `HEALTHCHECK` probes the FPM socket directly.

The entrypoint waits up to 60 s for the database before failing — without it, a
container starting fractionally before Postgres crash-loops and pollutes the
error tracker.

---

## Scheduler

Exactly **one** scheduler process across the fleet. Every task in
`routes/console.php` uses `onOneServer()` as a second defence, which requires
the Redis lock — do not run the scheduler with a non-shared cache store.

---

## Rollback

The image is immutable and tagged by SHA, so rolling back application code is
redeploying the previous tag.

**Database rollback is not symmetric.** `migrate:rollback` is for development.
In production, forward-fix: write a new migration that undoes the change. A
`down()` that drops a column destroys data written since the deploy.

---

## Scaling

| Component | Scale on |
|---|---|
| Web (FPM) | Request latency / CPU |
| Horizon | Queue wait time (`horizon.waits` thresholds) |
| PostgreSQL | Vertical first; then the configured `pgsql_read` replica connection |
| Redis | Vertical; `maxmemory-policy noeviction` so queue data is never evicted |
| OpenSearch | Add nodes before adding shards |

`noeviction` on Redis is deliberate: eviction under memory pressure would
silently delete queued jobs. Better to fail writes loudly.
