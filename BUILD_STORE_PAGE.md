# BUILD — Store page links (store slug on the offer + the order)

**Owner-side context.** The storefront now has a public **store page** at
`/magaza/{slug}` (Next.js — already built and pushed). It reads
`GET /api/v1/store/{slug}`, which already returns everything it needs (identity,
branding, SEO, contact, and Offer's `products` extension). **That page needs no
backend work.**

What this work order covers is the **links into it**. A shopper reaches a store
page from two places — a product's buy box and their own order list — and both
hold a store by **UUID**, while the page is **slug-addressed**. So each link needs
the store **slug** carried alongside the name it already shows (or would show).

This is small, read-only, and touches three files plus one contract. **No new
tables, no migrations, no events.** The storefront already renders the links
**conditionally** (`slug` is optional on both payloads there): nothing breaks
before you ship this, and the links light up the moment you do.

---

## Non-negotiables (unchanged, restated because they bite here)

- **Store is FROZEN.** The one contract change below is permitted precisely
  because a later surface *requires* it — the same footing as the two profile
  methods Offer already added to `StoreQueryContract`. **Record it in the
  `docs/001_Architecture.md` amendment log** in the same commit, and update the
  contract method's `@return` docblock. (CLAUDE.md → Store freeze notice.)
- **Order imports NO module.** It reads Store **only** through
  `App\Core\Domain\Contracts\StoreQueryContract`. Do not `use App\Modules\Store\…`
  anywhere in Order. `LayeringTest` fails the build on it.
- **UUID public, internal id never leaves.** The store crosses as its slug + name,
  never an internal id.
- `declare(strict_types=1)`, `make check` green before you call it done.

---

## Step 1 — Add `slug` to the Store profile read (the one contract change)

**File:** `app/Core/Domain/Contracts/StoreQueryContract.php`
**Method:** `publicProfilesFor(array $storeUuids): array`

Change the returned shape from `{name, city}` to **`{name, city, slug}`**, keyed by
store uuid as today. Update the `@return` docblock:

```php
 * @return array<string, array{name: string, city: string|null, slug: string}>
```

Add a sentence to the method doc: the slug was added (date) so a downstream module
can LINK to a store's public page (`/magaza/{slug}`), not just name it — the buy
box and the customer order list both hold a store uuid and render a link to the
slug-addressed page. Same footing as `city`, live-only for the same safety reason
(a suspended shop's slug must not reach a public payload).

**File:** `app/Modules/Store/Infrastructure/Queries/StoreQuery.php` (the impl)
Add `slug` to the `publicProfilesFor` select/projection. The slug is already a
column on `stores`; include it in the same live-only query that produces
`name`/`city`. No behaviour change beyond the extra field.

> **Amendment log:** append an entry to `docs/001_Architecture.md` (the amendment
> log at the end) — "`StoreQueryContract::publicProfilesFor()` gained `slug`
> (date), a frozen-Store change a later surface requires; store page links."

---

## Step 2 — Surface `slug` on the offer's `store` object (buy box)

**File:** `app/Modules/Offer/Presentation/Resources/PublicProductOffersResource.php`

The resource already receives the profile map and builds:

```php
'store' => [
    'id'   => $storeUuid,
    'name' => $store['name'] ?? null,
    'city' => $store['city'] ?? null,
],
```

Add one line:

```php
    'slug' => $store['slug'] ?? null,
```

Update the `@param` docblock on the constructor to the new
`array{name, city, slug}` shape. Nothing else in Offer changes — it already asks
`StoreQueryContract::publicProfilesFor(...)` in `PublicProductOfferController`, and
that now returns the slug.

**Result:** `GET /api/v1/products/{idOrSlug}/offers` → each `store` carries `slug`.
The storefront's product page (featured seller + "diğer satıcılar") turns each
name into a link automatically.

---

## Step 3 — Add a `store {name, slug}` object to the customer order

**File:** `app/Modules/Order/Presentation/Controllers/Api/CustomerOrderController.php`
**File:** `app/Modules/Order/Presentation/Resources/OrderResource.php`

The order carries `store_uuid` but no name/slug. Resolve them **batched** through
the Core contract and stamp each order before it hits the resource — the same
pattern Offer uses, and it avoids an N+1 on the order list.

1. Inject the contract into the controller:

   ```php
   public function __construct(
       private readonly OrderRepositoryContract $orders,
       private readonly CheckoutAction $checkout,
       private readonly PlaceOrderAction $place,
       private readonly CancelOrderAction $cancel,
       private readonly \App\Core\Domain\Contracts\StoreQueryContract $stores,
   ) {}
   ```

2. Add a small private helper that resolves profiles for a set of orders and
   stamps each one with a transient attribute the resource reads:

   ```php
   /**
    * @param iterable<int, Order> $orders
    * @return array<int, Order>
    */
   private function withStore(array $orders): array
   {
       $map = $this->stores->publicProfilesFor(array_values(array_unique(
           array_map(static fn (Order $o): string => (string) $o->store_uuid, $orders),
       )));

       foreach ($orders as $order) {
           $p = $map[$order->store_uuid] ?? null;
           // A transient attribute, not a column — the resource emits it.
           $order->setAttribute('store_profile', $p === null ? null : [
               'name' => $p['name'],
               'slug' => $p['slug'],
           ]);
       }

       return $orders;
   }
   ```

   Apply it in **`index()`**, **`show()`**, **`checkout()`**, **`place()`** and
   **`cancel()`** — everywhere an `OrderResource` is produced — so the field is
   consistent across the API. (`index()` passes `$orders->items()`; the collection
   endpoints pass their arrays/collections as arrays.)

3. In `OrderResource::toArray()`, emit it beside `store_id`:

   ```php
   'store_id' => $this->store_uuid,
   'store' => $this->store_profile ?? null,   // {name, slug} | null — a suspended
                                              // store is simply absent, never named
   ```

   `store_profile` is null when the store is not live (the profile read filters to
   live stores), so a link is never rendered to a suspended shop — the storefront
   already guards on `slug !== ''`.

**Result:** `GET /api/v1/orders` and the single-order reads → each order carries
`store: {name, slug}`. "Siparişlerim" renders a "{Mağaza adı} →" link under the
order number.

---

## Verification

- `GET /api/v1/products/{slug}/offers` → `featured.store.slug` and each
  `other_sellers[].store.slug` are present and non-empty for a live seller.
- `GET /api/v1/orders` (authenticated customer) → each row has
  `store: {name, slug}`; a suspended seller's order has `store: null`.
- A store you know the slug of renders at `/api/v1/store/{slug}` (already true) —
  the storefront page is `/magaza/{slug}`.
- `make check` green. `LayeringTest` green (Order still imports no module).
- Amendment log entry present.

## Out of scope

- No change to the store page payload itself (`/store/{slug}` is complete).
- No `city` backfill — it stays null until a seller contact-address form exists
  (Store.md §2.6); the slug is independent of it.
- No custom domains (cut from v1, ADR-035).
