# Work order — Storefront Phase C: deploy the Next.js app same-origin

**Disposable. `git rm` when done.** For the server-side session (has the box + root/nginx).
Owner-approved. Goal: run the `storefront/` Next.js app **on the same origin** as the Laravel
API so the owner can see it live with real products (ADR-058). Design: Storefront.md §2.3, §3.

**Coordination (agreed):** frontend lives in `storefront/` and is owned by the desktop
session; backend (`app/Modules/**`) by this server session. Do **not** edit `storefront/src`
here beyond what deploy strictly needs — just build + serve it.

## 0. Sync + prerequisites
- `git pull` (the desktop pushed the Manrope re-skin + design-lock).
- Node LTS is already on the box (you built the storefront here). Confirm `node -v` ≥ 18.18.
- **Phase A data must exist** so the listing isn't empty: run any pending
  `php artisan migrate --force` and the seeders, and make sure there is at least one
  **published product with an active in-stock offer** (the owner created some via the seller
  panel — Bioderma etc.). The home/listing only shows **sellable** products (ADR-058).

## 1. Build the app
```
cd storefront
npm ci            # or npm install
npm run build
```
Fix nothing in src — if the build fails on a real code error, STOP and report to the desktop
session (frontend owner), do not patch it here.

## 2. Run it as a service (systemd)
Create `/etc/systemd/system/raftabul-storefront.service` (adjust WorkingDirectory + the node
path to the box):
```
[Unit]
Description=Raftabul storefront (Next.js)
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/.../storefront
Environment=NODE_ENV=production
Environment=PORT=3000
ExecStart=/usr/bin/npm run start
Restart=always
RestartSec=3
StandardOutput=append:/var/log/raftabul-storefront.log
StandardError=append:/var/log/raftabul-storefront.log

[Install]
WantedBy=multi-user.target
```
```
systemctl daemon-reload && systemctl enable --now raftabul-storefront
systemctl status raftabul-storefront --no-pager
```
(After every deploy: `cd storefront && npm run build && systemctl restart raftabul-storefront`.)

## 3. nginx — same origin, Laravel keeps its paths, Next gets the rest
In the existing `test.raftabul.com` server block, route the **Laravel-owned** prefixes to
PHP-FPM (as today) and **everything else** to the Next process on `127.0.0.1:3000`.

Laravel keeps: `/api`, `/sanctum`, `/admin`, `/seller`, `/livewire`, `/build`, `/storage`,
`/store`, `/magaza`, `/vendor`, and the existing `location = /` index only if not caught by
Next — i.e. Laravel's public paths. Everything else → Next. Concretely, keep the current
`location /api { … fastcgi … }` (and the other Laravel prefixes above) and add:
```
location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
}
```
Order matters: the specific Laravel `location` blocks (prefix/regex) must win over `location /`.
Keep the Filament panels reachable — verify `/admin` and `/seller` still load Laravel after the
change. `test .` and `systemctl reload nginx`.

## 4. Auth (Sanctum SPA cookie) — same origin makes this easy
Since storefront and API share the origin, Sanctum "stateful" just works, but confirm the
Laravel `.env`:
- `SANCTUM_STATEFUL_DOMAINS` includes the host (e.g. `test.raftabul.com`).
- `SESSION_DOMAIN` set to the host (or leave null for same-origin).
- `config:clear` after any change.
The storefront calls `/sanctum/csrf-cookie` then `/api/v1/.../login` (already coded).

## 5. Verify (the point of this work order)
- Open `https://test.raftabul.com/` → the storefront home (orange hero, category shortcuts,
  coupon strip, **real** "Yeni eklenenler" products with "₺X'den başlayan" prices).
- Product page `/urun/{id}` → gallery + buy box (featured seller + others) from the API.
- `/urunler` listing; `/giris` + `/kayit`; add-to-cart sends an anonymous visitor to sign in;
  a signed-in customer can add to basket → `/sepet` → `/odeme` → order in `/hesap/siparislerim`.
- **The Filament panels (`/admin`, `/seller`) still work** (nginx routing intact).
- The queue worker (media/notifications) — if still not a service, run
  `php artisan queue:work redis --stop-when-empty` after test orders.

## Finish
`git rm BUILD_STOREFRONT_DEPLOY.md`, commit. Report the live URLs that work, anything that
404s or mis-routes, and whether the listing showed real products (if empty: no published
product has an active offer yet — that's data, not a bug). If a code error blocks the build,
STOP and hand it to the desktop (frontend) session.
