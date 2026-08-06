# FIX — nginx routes `/magaza` and `/store` to PHP, must go to Next.js

**Symptom.** `https://test.raftabul.com/magaza/turuncukasa` → **404**, and so does
every other store. A storefront rebuild does not fix it.

**Diagnosis (confirmed from response headers).** The request never reaches the
Next.js storefront — nginx sends `/magaza/*` and `/store/*` to **PHP-FPM**, and
Laravel has no web route for them (its store route lives under `/api/v1/…`), so
Laravel returns its own 404.

```
GET /sepet              → x-powered-by: Next.js        200   (Next serves it ✓)
GET /hesap              → x-powered-by: Next.js        200   (Next ✓)
GET /urunler            → x-powered-by: Next.js        200   (Next ✓)
GET /magaza/turuncukasa → phpdebugbar-id: …            404   (PHP served it ✗)
GET /store/turuncukasa  → phpdebugbar-id: …            404   (PHP served it ✗)
GET /api/v1/store/turuncukasa                          200   (correct — API works)
```

`phpdebugbar-id` on the `/magaza` and `/store` responses is the proof: only
Laravel (Debugbar) emits it. `x-powered-by: Next.js` on `/sepet` etc. is the
proof those go to Next. So the two prefixes are the only non-`/api` paths still
pointed at the backend — almost certainly a leftover from the original
path-addressed store design (ADR-035), where the store page was meant to be
backend-rendered before the storefront became a separate Next.js app.

## The fix (server nginx site config for test.raftabul.com — NOT in the repo)

`/magaza/*` and `/store/*` must fall through to the **Next.js upstream**, exactly
like `/sepet` does. Only `/api/*` (and the Filament panels + Laravel static
assets) stay on PHP-FPM.

Find the server block for `test.raftabul.com` and remove — or repoint to the
Next.js upstream — any `location` that captures `store` or `magaza`. It looks
something like one of these:

```nginx
# CULPRIT — delete these, or change their proxy_pass to the Next.js upstream:
location /store  { … fastcgi_pass / proxy_pass <php> … }
location /magaza { … fastcgi_pass / proxy_pass <php> … }
# or a combined regex:
location ~ ^/(store|magaza)(/|$) { … <php> … }
```

The correct routing is simply:

```nginx
location /api      { <PHP-FPM — Laravel>; }     # keep
location /admin    { <PHP-FPM — Filament>; }     # keep (if present)
location /seller   { <PHP-FPM — Filament>; }     # keep (if present)

location / {
    proxy_pass http://127.0.0.1:3000;            # Next.js — /magaza, /store,
    # …existing proxy headers…                    # /sepet, /urunler, everything
}
```

i.e. once the explicit `/store` and `/magaza` blocks are gone, they fall to
`location /` → Next.js, which already has both routes:
`/magaza/[slug]` (the page) and `/store/[slug]` (301 → `/magaza/{slug}`).

Then `nginx -t && systemctl reload nginx`. No backend code, no migration.

## Verify

```
curl -sI https://test.raftabul.com/magaza/turuncukasa | grep -iE 'HTTP|x-powered-by|phpdebugbar'
# want: HTTP/2 200  +  x-powered-by: Next.js   (NO phpdebugbar-id)

curl -sI https://test.raftabul.com/store/turuncukasa | grep -iE 'HTTP|location'
# want: 308/301  location: /magaza/turuncukasa
```

## Optional — make `/magaza` the SEO canonical (small, server env)

The public store resource builds its canonical URL from the FIRST configured
segment, which is currently `store` (`STORE_PUBLIC_PATH_SEGMENTS=store,magaza`),
so a crawler is told the canonical page is `/store/{slug}` while the real page is
`/magaza/{slug}` (a 301 to itself is harmless but not ideal). Reorder the env to
put the Turkish, user-facing segment first:

```
STORE_PUBLIC_PATH_SEGMENTS=magaza,store
```

Both still route to Next; only the canonical string flips to `/magaza/{slug}`,
which is the page that actually renders. `config:clear` after. Fully optional.
