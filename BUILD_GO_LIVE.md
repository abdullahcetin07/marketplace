# BUILD — Go-live cutover runbook (server-side, executed by the server session)

**This is an ordered runbook, not background work. It touches REAL MONEY (live PayTR),
a destructive data reset, and the production DNS/webserver. Two hard STOP gates need the
owner's explicit "go" in chat before proceeding (marked 🛑). Do them in order.**

Owner decisions (2026-08-19):
- **Deploy = Option B:** the app goes into a NEW prod directory alongside the existing
  installs; nothing is deleted until the new site is verified live.
- **Prod DB = a COPY of the current DB** (keeps the real catalogue, 114 stores, sellers,
  offers) with the test transactions purged via `marketplace:reset-commerce`.
- **`/test/` stays as STAGING** — its own separate DB, `noindex`, PayTR sandbox.
- **Old WP (`/var/www/www.raftabul.com/public/`) is retired** — backed up, then removed
  only AFTER prod is confirmed stable.

Target production directory below is a suggestion — pick your convention:
`/var/www/www.raftabul.com/app` (Laravel app root; its `public/` is the docroot).

---

## 0. Pre-flight backups (do first, no exceptions)
- [ ] Back up the **WP files**: `tar czf ~/wp-public-backup-$(date +%F).tgz -C /var/www/www.raftabul.com public`
- [ ] Dump the **WP MySQL DB** (its own DB — removing files does not drop it): `mysqldump … > ~/wp-db-$(date +%F).sql`
- [ ] Dump the **current app PostgreSQL DB** (this is the source for prod + stays as staging's DB): `pg_dump … > ~/app-db-$(date +%F).sql`
- [ ] Snapshot the current **nginx config** for the domain (you may need to revert): `cp /etc/nginx/sites-available/<vhost> ~/nginx-<vhost>-$(date +%F).bak`

## 1. Create the PROD database (a copy; keep the original for staging)
- [ ] Create a new Postgres DB + user for prod (e.g. `raftabul_prod`).
- [ ] Restore the app dump into it: `psql raftabul_prod < ~/app-db-$(date +%F).sql`. The
  current DB stays as-is and becomes **staging's** DB (test keeps using it).
- [ ] **APP_KEY caution:** if any DB columns are encrypted (e.g. 2FA secrets), the prod
  `.env` `APP_KEY` must be the SAME key that encrypted the copied rows, or those columns
  won't decrypt. Check whether the app encrypts anything (`Crypt`/`encrypted` casts). If
  it does and you want a fresh prod key, re-provision those columns after key change.

## 2. Deploy the app to the prod directory (WP untouched)
- [ ] `git clone` (or copy the release) into the prod dir. **Same commit as the verified
  staging build.**
- [ ] Write the **production `.env`** (do NOT copy staging's verbatim):
  - `APP_ENV=production`, `APP_DEBUG=false`, `DEBUGBAR_ENABLED=false`
  - `APP_URL=https://raftabul.com`
  - Prod Postgres creds (the `raftabul_prod` DB from step 1)
  - `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `CORS_ALLOWED_ORIGINS` = the real prod
    hostnames (`raftabul.com`, `www.raftabul.com`) — NOT localhost, NOT test.
  - `SESSION_SECURE_COOKIE=true` (auto under production — confirm)
  - Real mail creds; queue/cache/redis for prod.
  - **PayTR: LIVE creds, `test_mode` OFF** (step 5 verifies).
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `php artisan migrate` (the copy is already migrated; this is a no-op safety check —
  it should report nothing to migrate).
- [ ] `php artisan storage:link`
- [ ] `php artisan config:cache route:cache view:cache` (and `event:cache` if used).
- [ ] File permissions: `storage/` + `bootstrap/cache/` writable by the web user.

## 3. Storefront (Next.js) for prod
- [ ] In `storefront/`: prod env — `NEXT_PUBLIC_SITE_URL=https://raftabul.com` (and the
  API base if it's env-driven), then `npm ci && npm run build`.
- [ ] A **separate systemd service** for the prod storefront on its own port (don't reuse
  staging's). Reverse-proxied by nginx same-origin.

## 4. Queue workers + scheduler for prod (money-critical — the platform is inert without them)
- [ ] Prod **queue worker / Horizon** pointed at the prod app + Redis.
- [ ] Prod **scheduler** (`schedule:work` via systemd, like `raftabul-scheduler`) — this
  runs order-expiry (ADR-072), auto-payout, and the loyalty purchase sweep. Confirm it
  is actually running (the recurring ADR-072 lesson).

## 5. 🛑 STOP GATE #1 — PayTR LIVE (real money). Get the owner's "go".
- [ ] Set PayTR **live** merchant id/key/salt in prod `.env`, `test_mode=false`.
- [ ] In the PayTR merchant panel, set the **Bildirim (callback) URL** to the prod domain
  callback route (the CSRF-exempt one) + the merchant logo etc.
- [ ] After the site is live (step 6+), run **ONE small REAL payment** end-to-end and a
  **refund** of it to confirm live callback + hash + refund work (sandbox-passing ≠
  live-passing — the ADR-073 lesson). This costs real money; do it deliberately.

## 6. nginx + TLS: point the domain at the prod app
- [ ] New/edited server block for `raftabul.com` **and** `www.raftabul.com`:
  - `root` → the prod Laravel **`public/`** subdir (NOT the app root — exposing `.env`
    and source is a critical mistake).
  - Reverse-proxy the storefront routes to the prod Next.js port (same-origin).
  - Keep the PayTR callback route reachable + CSRF-exempt.
- [ ] **TLS**: issue certs for `raftabul.com` + `www` (certbot). 
- [ ] **Canonical host**: `SITE_URL` is the apex `raftabul.com`, so **301 `www` → apex**
  (or pick the other way and change `SITE_URL` to match — but be consistent).
- [ ] **Prod robots.txt**: allow crawling, `Sitemap: https://raftabul.com/sitemap.xml`,
  keep `/hesap /sepet /odeme /giris /kayit` disallowed. (Owner separately decides the
  Cloudflare AI-bot rule.)
- [ ] `nginx -t` then reload. **WP stops being served the moment the root changes** — it
  still exists on disk for rollback.

## 7. Staging hardening (`/test/`)
- [ ] `/test/` keeps its **own** DB (the original current DB), PayTR **sandbox**.
- [ ] Add sitewide **`noindex`** on staging: `X-Robots-Tag: noindex` header (or a staging
  robots `Disallow: /`) + ideally HTTP basic-auth, so it never competes with prod.

## 8. 🛑 STOP GATE #2 — reset test transactions on the PROD DB. Get the owner's explicit "go".
**`marketplace:reset-commerce` is DESTRUCTIVE and irreversible.** Run it **only on the
prod DB**, only after confirming the connection targets `raftabul_prod`, only with the
owner's explicit go in chat.
- [ ] Confirm the artisan command's DB connection = prod.
- [ ] Run `php artisan marketplace:reset-commerce` — purges test orders / payments /
  points / test customer accounts, **keeps** the catalogue, stores, sellers, offers.
- [ ] Verify: catalogue count unchanged (~19,886 products, 114 stores), orders/payments/
  ledger empty.

## 9. Smoke test on PROD (before announcing / before deleting WP)
- [ ] Home, a product, category, brand, store pages render; prices show.
- [ ] Register a real customer, log in, add to cart, checkout → **live PayTR** small
  payment (step 5), see the order Paid; then **refund** it.
- [ ] Points: signup credited; apply points at checkout works; refund re-credits.
- [ ] Seller panel + admin panel reachable and scoped.
- [ ] `https://raftabul.com/sitemap.xml` and `/robots.txt` resolve on the prod host.

## 10. Rollback plan (keep ready through the smoke test)
- If prod misbehaves: revert nginx to the backed-up vhost + reload → WP is served again
  instantly (it's still on disk). Prod app/DB stay for debugging.

## 11. Only AFTER prod is confirmed stable
- [ ] Archive + remove the old WP dir: `tar czf ~/wp-final-$(date +%F).tgz …` then remove
  `/var/www/www.raftabul.com/public` (or leave it unserved if disk allows).
- [ ] Set up backups/monitoring for the prod DB + app.

---

**Desktop (frontend) is ready:** `SITE_URL`/canonicals already target `raftabul.com`; the
sitemap includes catalogue + store pages; JSON-LD/metadata SEO fixes are in. Once DNS +
TLS resolve on `raftabul.com`, the storefront needs no further change for go-live.
