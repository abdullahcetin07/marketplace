# Work order — ops cleanup (server)

**Status:** approved by owner 2026-08-04 ("ops temizliğini yapalım"). Disposable —
`git rm` when done. Two recurring problems, fixed permanently.

---

## Fix 1 — storage perms: stop the root-owned-log 500 from recurring (OPS, one-time)

The log-permissions 500 has now bitten **twice** (admin login, then a plain 404
render). The deploy runbook already says "never run artisan as root", and the
`Deploy` section now prefixes every artisan call with `sudo -u www-data` — but a
reminder is not a guard. Apply the setgid hardening once so a stray root write can
no longer make the log unwritable:

```bash
cd /var/www/www.raftabul.com/test
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;   # 2 = setgid
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

setgid makes new files inherit the `www-data` group; group-write lets php-fpm append
to a log even if root created it. (Documented in `docs/storefront-deploy.md` →
"The permanent guard".) Nothing to commit here — it is a filesystem change; just
confirm it applied.

---

## Fix 3 — make `make lint` green (BACKEND)

`make lint` has never passed since the first commit (diagnosed earlier: composer.lock
was never versioned → a floating Pint within `^1.18` → each machine reformats
differently; plus one contradictory rule). This makes it deterministic and clean.

**The rule is already fixed (desktop):** `pint.json` now has
`not_operator_with_successor_space: true` — matching the codebase's `! $foo` (the
Laravel-preset style) instead of the old `!$foo` override. That alone clears ~70 of
the failures without reformatting anything.

**You do (the parts that need composer + Pint over the tree):**

1. **Pin Pint and version the lock.** composer.lock is untracked — commit it, and pin
   Pint to the installed `1.18.3` so CI and every box resolve the same formatter:
   ```bash
   composer require --dev laravel/pint:1.18.3 --no-interaction   # writes lock
   git add composer.json composer.lock
   ```
   (Packagist is reachable from this box — confirmed in the last report.)

2. **One-time formatting-only pass.** With the rule fixed and Pint pinned, run the
   fixer over the tree and commit the result **on its own**, no code changes mixed in:
   ```bash
   ./vendor/bin/pint          # or: make lint-fix
   ```
   Expect a large diff (phpdoc alignment, import ordering, etc.) — that is the point;
   it is the backlog that was never clean. Keep it a single `style:` commit.

3. **Verify** `make check` is now fully green (lint + analyse + test), and note in the
   commit that `make lint` passes for the first time.

**Boundaries:** formatting only — no behaviour changes, no test edits. If Pint wants
to change anything that looks semantic, stop and report rather than committing it.

---

## After

Commit + push (you should be able to now — see the SSH deploy key the owner is adding
so this session pushes itself). Tell the desktop session "bitti"; it will confirm
`make check` green from the pushed tree. No storefront rebuild needed for either fix.
