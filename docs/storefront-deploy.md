# Storefront deployment (Phase C)

How the Next.js storefront and the Laravel API share one origin on the box
(ADR-058, Storefront.md §3). Written down because the routing is the part nobody
can infer from the code.

---

## The shape

```
                     ┌────────────────────────────────────────────┐
  browser ──https──▶ │ nginx  test.raftabul.com                   │
                     │                                            │
                     │  /api /sanctum /admin /seller /livewire     │
                     │  /store /magaza /build /storage /vendor  ──▶│ PHP-FPM
                     │                                            │
                     │  everything else ───────────────────────▶  │ 127.0.0.1:3000 (Next)
                     └────────────────────────────────────────────┘

  Next server render ──▶ 127.0.0.1:8080 (nginx, loopback-only) ──▶ PHP-FPM
```

**One origin is the whole point.** Sanctum's SPA cookie, the CSRF token and the
session all work with no CORS, no second cookie domain and no base URL in the
storefront — it calls `/api/v1/...` relatively. That is ADR-058's reason for
existing, and splitting the origins later would break auth, not just tidiness.

## The two rules that bite

**1. nginx's Laravel prefixes are an allow-list.** `location /` proxies to Next,
so a Laravel route that is not listed with `^~` silently starts returning the
storefront's 404. Adding a Laravel path means adding it to
`/etc/nginx/sites-available/test.raftabul.com`.

**2. A server-side render cannot use a relative URL.** Next has no notion of
"this site" on the server, so the unit file sets `INTERNAL_API_URL` to the
loopback listener on `:8080`. Using the public HTTPS URL instead would send an
internal render out through DNS and TLS and back into the same box — and would
break outright if the certificate lapsed.

## Deploy

```bash
cd /var/www/www.raftabul.com/test
git pull
php artisan migrate --force          # if the backend changed
php artisan config:clear

cd storefront
npm ci
npm run build
systemctl restart raftabul-storefront
```

The service is `raftabul-storefront` (`/etc/systemd/system/`), runs as
`www-data`, binds `127.0.0.1:3000` — deliberately not `0.0.0.0`, so nothing
reaches the Node process except nginx — and logs to
`/var/log/raftabul-storefront.log`.

`npm run build` must happen **before** the restart: `next start` serves whatever
is in `.next`, so restarting first just re-serves the old build.

## Environment the auth depends on

In the Laravel `.env`:

- `SANCTUM_STATEFUL_DOMAINS` **must include the host.** Setting this variable
  overrides the config default that would have included it, so a host missing
  from the list is not treated as stateful and **every cookie-authenticated call
  401s** while looking perfectly configured.
- `SESSION_DOMAIN` — the host.
- `SESSION_SECURE_COOKIE=true`, because port 80 only redirects: a non-secure
  cookie is offered over a scheme it will never travel on.

`php artisan config:clear` after any change.

## Verifying a deploy

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://test.raftabul.com/          # storefront
curl -s -o /dev/null -w '%{http_code}\n' https://test.raftabul.com/admin     # 302 → /admin/login
curl -s -o /dev/null -w '%{http_code}\n' https://test.raftabul.com/api/v1/products
```

The panels are the ones to check: a routing mistake shows up there first, because
they are the paths `location /` would happily swallow.

**An empty listing is data, not a bug.** The storefront shows only *sellable*
products — published, with an active offer that Inventory says is available
(ADR-058). A product whose last unit is held by an in-flight order correctly
disappears until that order is placed or cancelled.

## The queue — `raftabul-horizon.service`

```bash
systemctl status raftabul-horizon      # is it running
php artisan horizon:status             # what it thinks
tail -f /var/log/raftabul-horizon.log  # what it is doing
php artisan queue:failed               # what it could not do
```

**Horizon, not `queue:work`.** `config/horizon.php` deliberately splits four
queues across three supervisors — `notifications`+`default`, `search`, `media` —
so a bulk product import cannot delay a password-reset mail. A bare
`queue:work redis` serves only `default` and leaves `search` and `media`
unattended, which is indistinguishable from a working worker until somebody
wonders why nothing is indexed.

`ExecStop` is `horizon:terminate`, not a kill: each worker finishes the job in
its hands first. SIGKILL mid-conversion leaves a half-written file and a job
already marked reserved.

## Media

Product images are served by Laravel from `/storage`. Conversions (`thumb`,
`preview`, `large` — all webp) are made by a queued job, so the worker above is
what makes an uploaded image appear.

**A missing conversion no longer means a missing image.** Spatie builds a
conversion URL by convention rather than by checking the disk, so a payload that
asks for `preview` unconditionally hands the browser a well-formed 404 for
everything the worker has not reached. Both the listing thumbnail and the product
gallery now fall back to the **original** file until the conversion exists — the
page is heavier for a minute and it works. To rebuild everything by hand:

```bash
php artisan media-library:regenerate --force
```

**"Görsel yok" with the queue healthy means the product genuinely has no images**
— on this deployment that is true of most of them, because they are seeded demo
rows.

## OpenSearch is not running on this box

`SCOUT_DRIVER=opensearch` and `OPENSEARCH_HOST=opensearch` — the Docker Compose
service name, which resolves to nothing on bare metal. Every `MakeSearchable` /
`RemoveFromSearch` job therefore fails on the `search` queue. Standing up the
worker did not cause this; it made it **visible**, since the jobs previously just
sat in Redis unattended.

Nothing customer-facing depends on it today: the public browse reads Postgres
(`PublicProductBrowse`), and the seller's catalogue search reads Postgres by
design (Offer.md §8.2). It needs an owner decision — run OpenSearch here, or set
`SCOUT_DRIVER=null` on this box — and until then `failed_jobs` grows slowly.
