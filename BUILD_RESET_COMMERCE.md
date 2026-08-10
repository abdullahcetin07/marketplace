# BUILD — `marketplace:reset-commerce` (wipe test catalog + commerce, KEEP accounts/stores/config)

**Owner-approved (2026-08-08).** The owner will enter their REAL categories, brands and
products, so all TEST catalog + commerce data is deleted. **Accounts, stores and config
survive.** This is a **DESTRUCTIVE** maintenance command — it is run **once, by hand, with
confirmation**, never on a schedule.

> ⚠️ **Nothing here is reversible.** The command must refuse to run without an explicit
> confirmation (or `--force`), print exactly what it will delete and keep, and report row
> counts. It runs against whatever DB the app is pointed at — the operator confirms that.

---

## What is DELETED vs KEPT

The catalog is uuid-linked with **no FK to offers/inventory** (ADR-040), so deleting
`products` does NOT cascade to `offers`/`stock_items` — each group is truncated
independently. Truncate the DELETE tables; leave the KEEP tables untouched.

**DELETE (test catalog + commerce):**
| Module | Tables |
|---|---|
| Catalog | `products`, `product_variants`, `product_attribute_value`, `variant_attribute_value`, `attributes`, `attribute_values`, `category_attribute`, `categories`, `brands`, `slugs` |
| Offer | `offers` |
| Inventory | `stock_reservations`, `stock_movements`, `stock_items` |
| Order | `order_lines`, `orders`, `cart_items`, `carts`, `return_requests`, `cancellation_requests` |
| Payment | `payment_refund_lines`, `payment_refunds`, `payments`, `seller_ledger_entries`, `settlement_windows`, `payouts` |
| Shipping | `shipments` |
| Reviews | `reviews` |
| Questions | `questions` |
| Media | rows for the deleted model types only (§media) |

**KEEP (accounts, stores, config, evidence) — do NOT touch:**
- Auth/accounts: `users`, password-reset tables, `sessions`, `personal_access_tokens`,
  `user_devices`, `user_sessions`, `login_attempts`.
- Roles/permissions: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`,
  `role_has_permissions`.
- Organization: `organizations`, `organization_members`, `organization_invitations`,
  `organization_plans`, `organization_bank_accounts`, `organization_kyc`,
  `organization_documents`, `store_opening_requests`.
- Store: `stores`, `store_settings`, `store_branding`, `store_seo`, `store_contacts`.
- **Config lookups: `tax_rates`, `commission_rules`, `cargo_companies`, `payment_methods`,
  `currencies`, `countries`, `languages`, `timezones`, `translations`, `geo_*`, `settings`.**
- **Evidence (append-only): `audit_entries`, `activity_entries` — KEEP.** Truncating them
  destroys the audit trail (`audit_entries` has no `updated_at` by design).
- `customer_addresses` — **KEEP by default** (it belongs to the surviving customer
  accounts; orders snapshot their own copy so nothing dangles). Put it behind an opt-in
  `--include-addresses` flag if the owner wants it gone too.
- Framework: `cache`, `jobs`, `job_batches`, `failed_jobs`, `notifications`/
  `notification_preferences` (KEEP; delete stray notification rows only if asked).

---

## The command

**File:** `app/Console/Commands/ResetCommerceCommand.php` — `marketplace:reset-commerce
{--force} {--include-addresses}`.

1. **Guard + confirm.** Print the DELETE and KEEP lists (or a summary). Unless `--force`,
   `$this->confirm('TÜM test katalog + ticaret verisi silinecek (ürün/sipariş/ödeme/…),
   hesaplar + mağazalar + config KALACAK. Devam?')` → abort on no. Optionally refuse if
   `app()->environment('production')` without `--force`.
2. **Clear orphaned media FIRST** (before truncating, so you still have the rows). For each
   `Media` row whose `model_type` is one of the deleted models
   (`Product`, `ProductVariant`, `Review`, and any question/offer media if used):
   `Storage::disk($row->disk)->deleteDirectory((string) $row->id)` (spatie stores each
   media item under a folder named by its id), then let the row be truncated with the
   table — OR delete the rows explicitly here. **Do NOT truncate the whole `media` table**
   — store branding logos live there too and are KEPT. Resolve the disk per row (`$row->disk`),
   not from config, since it varies (`s3` / local `public`).
3. **Truncate the DELETE tables, FK-safe.** On PostgreSQL the clean way is to suspend FK
   triggers for the session, truncate each with identity reset, then restore:
   ```php
   DB::statement('SET session_replication_role = replica');
   foreach ($deleteTables as $t) { DB::statement("TRUNCATE TABLE {$t} RESTART IDENTITY"); }
   DB::statement('SET session_replication_role = origin');
   ```
   (Or `TRUNCATE a, b, c, … RESTART IDENTITY CASCADE` in one statement — but the KEEP
   tables must not be swept in by CASCADE, so the `session_replication_role` approach is
   safer and explicit.) Wrap in try/finally so the role is always restored.
4. **Report** per-table deleted counts (capture `count()` before truncate) and a media-file
   tally. Emit an `AuditLogger`/log line "commerce reset by {actor} — N tables, M rows".

**No migration, no schema change.** Config that was KEPT needs no re-seed. The taxonomy
(`categories`/`brands`) is left **empty** on purpose — the owner creates real ones. (Do NOT
re-run `CatalogTaxonomySeeder`/demo seeders; there is no faker product seeder to undo — the
test data came from factories, not a seeder.)

---

## Run (server, by hand)

```bash
php artisan marketplace:reset-commerce        # prompts for confirmation
# or, to also wipe saved customer addresses:
php artisan marketplace:reset-commerce --include-addresses
php artisan optimize:clear
```

After it runs: the storefront shows an empty catalog (no products), the seller panel shows
no offers, and the owner enters real categories → brands → products → offers. Accounts and
stores are intact — no re-registration.

## Test

`tests/Feature/Console/ResetCommerceCommandTest.php`: seed a product + offer + inventory +
order + review via factories, run the command with `--force`, assert the DELETE tables are
empty and the KEEP tables (a user, a store, `tax_rates`, `cargo_companies`) are untouched.
Assert `audit_entries` is preserved.

## Steps

Command → test → `make check` green → commit + push `feat(ops): marketplace:reset-commerce —
wipe test catalog/commerce, keep accounts+stores+config`. **Do not run it on live until the
owner says go.**
