# Work order — two SEO fix-ups (backend + ops)

**Status:** approved by owner 2026-08-04. Disposable — `git rm` when done. Follows the
flat-slug SEO work (ADR-059). Frontend is done and verified; these are the two
loose ends the live check surfaced. Do **not** touch `storefront/`.

---

## Fix 1 — nginx: serve `/robots.txt` from Next, not a 404 (OPS)

**Symptom:** after `public/robots.txt` was removed, `/robots.txt` no longer returns
Next's storefront robots — Cloudflare's managed AI-bot block is all that's left, so
the `Sitemap:` directive and the private-page disallows are missing.

**Cause:** nginx isn't routing `/robots.txt` to Next (an explicit static-file rule
404s it instead of falling through to the Next proxy — unlike `/sitemap.xml`, which
had no static file and fell through, which is why it works).

**Do:** route `/robots.txt` to Next explicitly, before the `location /` proxy block,
mirroring how the app is proxied:

```nginx
location = /robots.txt {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

Remove any leftover `location = /robots.txt` that pointed at Laravel's public dir.
Then `sudo nginx -t && sudo systemctl reload nginx`.

**Verify:**
```bash
curl -s https://test.raftabul.com/robots.txt | grep -Ei 'sitemap|Disallow: /hesap'
```
Should show `Sitemap: https://test.raftabul.com/sitemap.xml` and `Disallow: /hesap`.
(Cloudflare will still prepend its managed AI-bot block above Next's output — that's
fine; it blocks GPTBot/ClaudeBot/Google-Extended, not Googlebot.)

---

## Fix 2 — `/products/{slug}/offers` must resolve slug-or-uuid (BACKEND)

**Symptom:** `GET /api/v1/products/{slug}/offers` → **500** (works with a uuid). The
storefront already works around it (fetches offers by the resolved uuid), but the
endpoint itself is the **4th appearance of the uuid-cast trap** and must be guarded
like every other public lookup.

**Do:** in the offers controller/action, resolve the `{idOrSlug}` route param through
the same `App\Shared\Support\PublicKey` shape check product detail uses — uuid-pattern
→ uuid, else slug → the product's id — then load offers by that id. **404 on miss,
never 500.**

**Test (extend the pgsql taxonomy/offers feature test):**
- `/products/{slug}/offers` → 200
- `/products/{uuid}/offers` → 200 (unchanged)
- `/products/{unknown}/offers` → 404, **not 500**

`make check` green. Report: the guard location + the test names.

---

## After

Commit + push both (nginx change isn't in git — just note it applied and add a line
to `docs/storefront-deploy.md`). Storefront needs **no** rebuild for either. Tell the
desktop session "bitti" and it re-verifies `/robots.txt` + `/products/{slug}/offers`.
