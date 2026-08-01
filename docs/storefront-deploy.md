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

## Media

Product images are served by Laravel from `/storage`, and the conversions are
made by a queued job. There is **no queue worker service yet**, so after
uploading images somebody must run:

```bash
php artisan queue:work redis --stop-when-empty
```

Until that becomes a systemd unit of its own, freshly uploaded product images
render as "görsel yok" on the storefront.
