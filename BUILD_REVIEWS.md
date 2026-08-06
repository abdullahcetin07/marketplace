# BUILD — Reviews module (feedback #5)

**Spec:** `docs/modules/Reviews.md` (ADR-066–069). Read it first — this work order
implements exactly that, nothing more. **Frontend (the Next.js storefront reviews
UI) is NOT in this work order** — that is the desktop session's job once these
endpoints land; §R9 records the API contract both sides build to.

**What Reviews is:** one buyer's rating (1–5) + optional text + optional photos of a
product they were **delivered**, published **only after Admin/Editor moderation**.
Shared catalogue → the review is the **product's** and carries the **seller it was
bought from** as a tag copied from the delivered order line, never chosen by the
buyer. Binds to one delivered order line (`order_line_uuid` UNIQUE). Rating average
is the product's, computed on read.

Build the phases **in order**. Each phase ends with `make check` green and a
commit + push. Do not build ahead. If anything here contradicts the spec or an
existing decision, **STOP and report** (ADR-018) — do not pick a side silently.

---

## Non-negotiables (enforced by tests, restated because they bite here)

- **`declare(strict_types=1)`** in every PHP file. No `dd/dump/var_dump/die/exit`.
- **Reviews imports NO module.** It reaches Catalog and Order **only** through
  `app/Core/Domain/Contracts` and cross-module events **by class-string**. Photos use
  `App\Shared\Traits\HasMedia` (NOT the Media module). Store names come from
  `StoreQueryContract`. `tests/Architecture/LayeringTest.php` fails the build on any
  import, both directions.
- **UUID public, internal `id` never leaves the app** (non-negotiable #7). Route-model
  binding is by uuid (the `HasUuid` trait sets `getRouteKeyName()`).
- **No money in this module** — a `rating` is a small integer, not a price. The
  minor-units rule does not apply.
- **No `cache()`/`request()`/`encrypt()` in Domain** (ADR-019). `now()`/`config()` OK.
- **DTOs** carry the `DTO` suffix in `Reviews/Domain/DTOs/` (ADR-021).
- **Enums** carry no `Enum` suffix (ADR-007).
- **Permissions** via `PermissionRegistry`; never hand-write names; `make permissions`.
- **`make check` green before any phase is "done".**

---

## Verified facts (so you don't re-derive them)

- `orders`: `customer_id` (int), `customer_uuid`, `store_uuid`, `selling_org_uuid`,
  `status` (string), `placed_at` — **NO `delivered_at`** (delivery is only
  `status='delivered'`; the timestamp lives on Shipping's `shipments`, which we do
  NOT read — v1 has no review window).
- `order_lines`: `uuid`, `order_id` (FK→orders, cascade), `product_uuid` (**currently
  unindexed**), `variant_uuid`, `variant_label`, `product_title`, `quantity`.
- `OrderStatus::Delivered = 'delivered'` (`app/Modules/Order/Domain/Enums/OrderStatus.php`).
- `UserType` has **exactly** `Admin`, `Seller`, `Customer` (Editor/Category-Manager are
  ROLES, not UserTypes). `app/Shared/Enums/UserType.php`.
- Traits: `App\Shared\Traits\HasUuid` (auto-uuid on create, route key = uuid, needs a
  `uuid` column), `App\Shared\Traits\HasMedia` (model must also `implements
  \Spatie\MediaLibrary\HasMedia`; `images` collection; `imageGallery()` returns
  uuid/name/alt/order/thumb/preview/large).
- Base classes (`app/Core`): `BaseAction` (override `handle()`, call via `->run()`;
  hooks `before/after/onFailure`; `$useTransaction=true`), `BasePolicy` (implement
  `permissionPrefix()`, override `owns()` — default false), `BaseResource`
  (`publicId()`, `whenPermitted()`), `BaseController` (`ok()/created()/paginated()/
  perPage()`, envelope `{success,data,meta?}`), `BaseRequest` (`authorize()` default
  **false** — override; `rules()`; `toDto()` with `protected ?string $dto`).
- Slug-or-uuid on public product routes: `CatalogBrowseContract::publishedProductUuidFor($idOrSlug)`
  → null for missing/unpublished/non-product; same 404 for all three (never leak).
- Store names for the seller tag: `StoreQueryContract::publicProfilesFor(array $storeUuids)`
  → `uuid => {name, city, slug}` (slug landed in the store-page work; live stores only).
- Routes (`routes/api.php`, all under `prefix('v1')->name('api.v1.')->middleware(SetLocale)`):
  public storefront group = `->middleware('throttle:storefront')`; customer-auth group =
  `->middleware(['auth:sanctum','throttle:api'])`. Declare specific paths BEFORE the
  `products/{product}` catch-all.
- Filament moderation to mirror: `Catalog/.../ProductModerationResource.php`
  (`canCreate/canEdit/canDelete=false`, `getNavigationBadge()`, `SelectFilter` default
  `PendingReview`, per-verb `Action::make()` calling `app(XAction::class)->run(...)`,
  reject uses a `Textarea::make('reason')` form, a `decide()` try/catch helper).
  Register in `app/Providers/Filament/AdminPanelProvider.php` `->resources([...])`.
- Tests: **Pest**; Feature/Modules get `RefreshDatabase`. Helpers on `tests/TestCase.php`:
  `seedPlatform()`, `seedRolesAndPermissions()`, `actingAsCustomer()`, `actingAsSeller()`,
  `actingAsAdmin()`, `grant(User, ...permissions)`. Module tests under
  `tests/Modules/Reviews/Feature/`. Factories in `database/Modules/Reviews/Factories/`.

---

## Phase R0 — Scaffold & boundary

**Goal:** an empty, wired, layering-tested module that boots.

**Files:**
- Create `app/Modules/Reviews/ReviewsServiceProvider.php` — `register()` (bind
  `ReviewRepositoryContract` → `ReviewRepository` singleton; call the two
  `PermissionRegistry` lines below), `boot()`
  (`loadMigrationsFrom(database_path('Modules/Reviews/migrations'))`, `Gate::policy(Review::class, ReviewPolicy::class)`).
- Register the provider in `bootstrap/providers.php`.
- Create `app/Modules/Reviews/README.md` (one paragraph pointing at `docs/modules/Reviews.md`).
- Modify `app/Modules/README.md` — add Reviews to the module index.
- Modify `tests/Architecture/LayeringTest.php` — add the two arch blocks + the Domain
  helper-forbidden entry (below).
- Create the directory skeleton: `Reviews/{Application/{Actions,Services},Domain/{Contracts,DTOs,Enums,Events,Models},Infrastructure/{Queries,Repositories},Presentation/{Controllers/Api/{Storefront},Filament/Resources,Policies,Requests,Resources}}`.

**Permissions (in `register()`):**
```php
PermissionRegistry::resource('review', [UserType::Admin]);      // view_any/view for the queue
PermissionRegistry::ability('review.moderate', [UserType::Admin]); // approve/reject
```

**LayeringTest additions (write them out, no loops):**
```php
arch('Reviews imports no other module')
    ->expect('App\Modules\Reviews')
    ->not->toUse([
        'App\Modules\Catalog', 'App\Modules\Order', 'App\Modules\Store',
        'App\Modules\Offer', 'App\Modules\Inventory', 'App\Modules\Payment',
        'App\Modules\Shipping', 'App\Modules\Organization', 'App\Modules\Media',
        'App\Modules\Identity', 'App\Modules\Notification',
    ]);

arch('no module depends on Reviews')
    ->expect('App\Modules\Reviews')
    ->toOnlyBeUsedIn([
        'App\Modules\Reviews', 'App\Providers\Filament',
        'Database\Modules\Reviews', 'Tests\Modules\Reviews',
    ]);
```
Add `App\Modules\Reviews\Domain` to the existing "no cache/request/encrypt in Domain"
expectation list.

**Steps:** create files → `make check` (LayeringTest green, app boots) → commit + push
`feat(reviews): R0 scaffold + boundary`.

---

## Phase R1 — Domain

**Goal:** the enum, model, contract, DTOs, events — no persistence yet.

**`Domain/Enums/ReviewStatus.php`** — `enum ReviewStatus: string` (use `HasEnumHelpers`
like `OrderStatus`):
```php
case PendingReview = 'pending_review';
case Published     = 'published';
case Rejected      = 'rejected';
// helpers: isPublished(): bool; awaitsModeration(): bool (=== PendingReview);
//          moderationOutcomes(): array => [Published, Rejected]; label(): string; color(): string;
```

**`Domain/Models/Review.php`** — `final class Review extends Model implements \Spatie\MediaLibrary\HasMedia`:
```php
use HasFactory, HasUuid, App\Shared\Traits\HasMedia;
protected $fillable = [
  'product_uuid','variant_uuid','order_line_uuid','customer_id','customer_uuid',
  'author_name','store_uuid','selling_org_uuid','rating','body','status',
  'has_photos','moderated_at','moderated_by','moderation_reason',
];
protected function casts(): array {
  return ['rating' => 'integer', 'has_photos' => 'boolean',
          'status' => ReviewStatus::class, 'moderated_at' => 'immutable_datetime'];
}
// query scopes: scopePublished($q) => where('status', ReviewStatus::Published);
//               scopeForProduct($q, string $productUuid);
protected static function newFactory() { return \Database\Modules\Reviews\Factories\ReviewFactory::new(); }
```

**`Domain/Contracts/ReviewRepositoryContract.php`:**
```php
public function create(array $attributes): Review;
public function findByUuid(string $uuid): ?Review;
/** Published reviews for a product, filtered + paginated. */
public function publishedForProduct(string $productUuid, ReviewListFilterDTO $filter): LengthAwarePaginator;
/** {average: string, count: int, distribution: array<int,int>, with_images_count: int,
 *   sellers: array<int, array{store_uuid:string, count:int}>} — published only. */
public function summaryForProduct(string $productUuid): array;
/** @param array<int,string> $productUuids  @return array<string, array{average:string, count:int}> */
public function summariesForProducts(array $productUuids): array;
/** All statuses, one customer, newest first. */
public function forCustomer(int $customerId): Collection;
/** @return array<int,string> order_line_uuids this customer already reviewed for the product. */
public function reviewedOrderLineUuids(int $customerId, string $productUuid): array;
public function delete(Review $review): void;
```

**`Domain/DTOs/`:**
- `SubmitReviewDTO` — `orderLineUuid, rating (int), body (?string), productUuid,
  customerId (int), customerUuid, authorName` (masked; computed in the controller).
  `readonly`. **It carries NO store/seller/variant** — those are copied authoritatively
  from the matched eligible line inside `CreateReviewAction` (ADR-066), never from input.
- `ReviewModerationDTO` — `moderatedBy (int), reason (?string = null)` (Reviews' own; do
  NOT import Catalog's `ModerationDecisionDTO`).
- `ReviewListFilterDTO` — `sellerStoreUuid (?string), withImages (bool=false),
  rating (?int=null), page (int=1), perPage (int=20)`.

**`Domain/Events/`** — `ReviewSubmitted`, `ReviewPublished`, `ReviewRejected`. Payload
= ids + uuids, no models: e.g. `ReviewPublished::dispatch(int $id, string $uuid,
string $productUuid, int $moderatedBy)`.

**Steps:** write these → a small unit test asserting `ReviewStatus` helpers +
model casts → `make check` → commit + push `feat(reviews): R1 domain`.

---

## Phase R2 — Infrastructure: migration + repository + factory

**`database/Modules/Reviews/migrations/*_create_reviews_table.php`:**
```php
$table->bigIncrements('id');
$table->uuid('uuid')->unique();
$table->uuid('product_uuid');
$table->uuid('variant_uuid')->nullable();
$table->uuid('order_line_uuid')->unique();          // ← the per-purchase gate (ADR-067)
$table->unsignedBigInteger('customer_id');
$table->uuid('customer_uuid');
$table->string('author_name');                       // masked snapshot "Abdullah Ç."
$table->uuid('store_uuid');                           // seller tag (ADR-066) + filter
$table->uuid('selling_org_uuid');
$table->unsignedTinyInteger('rating');               // 1..5 (checked in the action/request)
$table->text('body')->nullable();
$table->string('status', 20)->default('pending_review');
$table->boolean('has_photos')->default(false);       // set on attach — avoids a media join on the hot read
$table->timestampTz('moderated_at')->nullable();
$table->unsignedBigInteger('moderated_by')->nullable();
$table->text('moderation_reason')->nullable();
$table->timestampsTz();
$table->index(['product_uuid','status']);            // public list + summary
$table->index('status');                             // moderation queue
$table->index('customer_id');                        // /reviews/mine
$table->index('store_uuid');                         // seller filter
```

**Second migration (under Order), for the gate query performance:**
`database/Modules/Order/migrations/*_add_product_uuid_index_to_order_lines.php` —
`$table->index('product_uuid');` on `order_lines` (currently unindexed; the gate joins
on it). Order is not frozen; this is a plain index.

**`Infrastructure/Repositories/ReviewRepository.php`** — implements the contract with
Eloquent. `summaryForProduct` computes with grouped queries (published only):
`average` = `round(avg(rating),1)` returned as a **decimal string** (e.g. `"4.3"`;
never a float to the API), `distribution` = counts grouped by `rating` filled for 1..5,
`with_images_count` = `where('has_photos', true)->count()`, `sellers` = counts grouped
by `store_uuid`. `summariesForProducts` = one grouped query keyed by product; an absent
product is simply not in the map (never `"0.0"`).

**`database/Modules/Reviews/Factories/ReviewFactory.php`** — `definition()` with invented
uuids (no cross-module model imports), states `published()`, `pending()`, `rejected()`,
`forProduct(string $uuid)`, `forCustomer(int $id, string $uuid)`, `withRating(int)`.

**Tests (`tests/Modules/Reviews/Feature/ReviewRepositoryTest.php`):**
- `summaryForProduct` averages only **published** reviews; excludes pending/rejected.
- distribution buckets 1..5 sum to count; a product with no reviews → `count:0`,
  `average:"0.0"` (repo can return that; the *batch* endpoint omits it — assert both).
- `reviewedOrderLineUuids` returns the right line uuids.
- inserting a second review with the same `order_line_uuid` throws (unique).

**Steps:** migration → repo → factory → tests → `make check` → commit + push
`feat(reviews): R2 migration + repository`.

---

## Phase R3 — Purchase gate on `OrderQueryContract` (the one Core change)

**Modify** `app/Core/Domain/Contracts/OrderQueryContract.php` — add:
```php
/**
 * Delivered order lines a customer holds for a product — the review gate (ADR-067).
 * Keyed by customer UUID (this contract keys on uuid, never internal id — #7).
 * Delivered only; empty when none.
 *
 * @return array<int, array{order_line_uuid: string, store_uuid: string,
 *                          selling_org_uuid: string, variant_uuid: string|null,
 *                          variant_label: string|null, product_title: string,
 *                          purchased_at: string|null}>
 */
public function deliveredPurchaseLines(string $customerUuid, string $productUuid): array;
```

**Modify** `app/Modules/Order/Infrastructure/Queries/OrderQuery.php` — implement it
(Eloquent, mirroring the file's style; `purchased_at` = the order's `placed_at`
ISO-8601, since there is no delivered_at):
```php
return Order::query()
    ->where('customer_uuid', $customerUuid)
    ->where('status', OrderStatus::Delivered->value)
    ->with(['lines' => fn ($q) => $q->where('product_uuid', $productUuid)])
    ->get()
    ->flatMap(fn (Order $o) => $o->lines->map(fn (OrderLine $l) => [
        'order_line_uuid'  => $l->uuid,
        'store_uuid'       => $o->store_uuid,
        'selling_org_uuid' => $o->selling_org_uuid,
        'variant_uuid'     => $l->variant_uuid,
        'variant_label'    => $l->variant_label,
        'product_title'    => $l->product_title,
        'purchased_at'     => $o->placed_at?->toIso8601String(),
    ]))
    ->values()->all();
```

**Amendment log:** add a row to the table at the end of `docs/001_Architecture.md`
(next sprint number) — "`OrderQueryContract` gains `deliveredPurchaseLines` (uuid-keyed),
a read the Reviews module requires — the review gate; Order owns orders and the delivered
state." Mention it in `docs/modules/Order.md`'s follow-ups too.

**Tests (`tests/Modules/Order/Feature/DeliveredPurchaseLinesTest.php`):**
- a delivered order's matching lines are returned; a `paid` (not delivered) order's are
  not; another customer's are not; a different product's are not.

**Steps:** contract → impl → amendment log → tests → `make check` → commit + push
`feat(order): deliveredPurchaseLines for the review gate (ADR-067)`.

---

## Phase R4 — Application actions + policy + eligibility

**`Application/Services/ReviewEligibilityService.php`** — depends on
`OrderQueryContract` + `ReviewRepositoryContract`:
```php
/** Delivered lines for (customer, product) not yet reviewed. Same array shape as
 *  deliveredPurchaseLines, filtered. */
public function eligibleLines(int $customerId, string $customerUuid, string $productUuid): array;
```
(subtract `reviewedOrderLineUuids($customerId, $productUuid)` from
`deliveredPurchaseLines($customerUuid, $productUuid)`.)

**`Application/Actions/CreateReviewAction.php`** (extends `BaseAction`, `handle(SubmitReviewDTO $dto, array $photos = []): Review`):
1. **Re-verify server-side** (never trust the client): the `order_line_uuid` is in
   `eligibleLines(...)` for this customer + product — else throw
   `ReviewException::notEligible()`. This closes the gate at the action, not the request.
2. Create the review `status = PendingReview`, copying `store_uuid`/`selling_org_uuid`/
   `variant_uuid` **from the eligible line** (authoritative — ADR-066), not from input.
3. Attach photos via `$review->addMedia($file)->toMediaCollection('images')` for each;
   set `has_photos = ! empty($photos)`.
4. `after()`: `ReviewSubmitted::dispatch(...)`.

**`Application/Actions/PublishReviewAction.php`** (`handle(Review $review, ReviewModerationDTO $dto): Review`):
guard `status->awaitsModeration()` else `ReviewException::notPending()`; set
`status=Published`, `moderated_at=now()`, `moderated_by=$dto->moderatedBy`;
`after()`: `ReviewPublished::dispatch(...)`.

**`Application/Actions/RejectReviewAction.php`** — same guard; set `status=Rejected`,
`moderation_reason=$dto->reason`, stamps; `after()`: `ReviewRejected::dispatch(...)`.

**`Application/Actions/DeleteReviewAction.php`** (`handle(Review $review): void`) —
hard delete (`$review->delete()`; frees the `order_line_uuid`). Ownership is enforced by
the policy at the controller, not here.

**`Domain/Exceptions/ReviewException.php`** (extends `BaseException`, `$reportable=false`):
`notEligible()`, `notPending()`, `alreadyReviewed()` — each with a Turkish message + a
status (`422`/`409`).

**`Presentation/Policies/ReviewPolicy.php`** (extends `BasePolicy`):
`permissionPrefix(): string => 'review'`; override `owns(User $user, Model $review): bool`
= `$review->customer_id === (int) $user->getKey()` (for `delete`); a `moderate(User)`
ability = `$user->can('review.moderate')`. `ownershipRequiredFor()` = `['delete']`.

**Tests (`tests/Modules/Reviews/Feature/ReviewGateTest.php`):** using a **fake**
`OrderQueryContract` bound in the container —
- a customer with a delivered line can create a `PendingReview`.
- a customer with only a `paid` (not delivered) line → `notEligible` (422).
- reviewing another customer's line → `notEligible`.
- reviewing the same line twice → second is rejected (`alreadyReviewed`/unique).
- publish moves Pending→Published + stamps; publishing a non-pending review → `notPending`.

**Steps:** actions → policy → exception → tests → `make check` → commit + push
`feat(reviews): R4 actions + gate + policy`.

---

## Phase R5 — Public read API (product page + card badges)

**`Presentation/Controllers/Api/Storefront/PublicProductReviewController.php`** —
`index(Request $r, string $product): JsonResponse`:
- resolve `$productUuid = app(CatalogBrowseContract::class)->publishedProductUuidFor($product)`;
  null → `ReviewException::productNotFound()->withStatus(404)` (same 404 as offers).
- build `ReviewListFilterDTO` from query (`seller`, `with_images`, `rating`, `page`).
- `$page = $reviews->publishedForProduct($productUuid, $filter)`; `$summary =
  $reviews->summaryForProduct($productUuid)`.
- batch-resolve store names for the page's `store_uuid`s + the summary's `sellers` via
  `StoreQueryContract::publicProfilesFor([...])`.
- `return $this->paginated($page, PublicReviewResource::collection($page->items()),
   null)->additional(['summary' => (new ReviewSummaryResource($summary, $storeNames))])` —
  (or fold summary into `meta`; keep `data` = reviews, `meta.summary` = the summary).

**`ProductRatingsController.php`** — `batch(BatchRatingsRequest $r): JsonResponse`:
input `{product_ids: [uuid,...]}` (max 100), `return $this->ok(['ratings' =>
$reviews->summariesForProducts($ids)])`. Mirrors `POST /offers/prices`.

**Resources:**
- `PublicReviewResource` — `{id: uuid, rating, body, author_name (already masked),
  seller: {id: store_uuid, name}, variant_label, images: imageGallery()-derived
  [{thumb,preview,large}], created_at}`. **No customer_id/customer_uuid, no order_line_uuid**
  (internal / private).
- `ReviewSummaryResource` — `{average, count, distribution:{5,4,3,2,1},
  with_images_count, sellers:[{id: store_uuid, name, count}]}`.

**Routes** (`routes/api.php`, in the `throttle:storefront` group, **before**
`products/{product}` catch-all):
```php
Route::get('products/{product}/reviews', [PublicProductReviewController::class, 'index'])
    ->name('storefront.product.reviews');
Route::post('products/ratings', [ProductRatingsController::class, 'batch'])
    ->name('storefront.products.ratings');
```

**Tests (`tests/Modules/Reviews/Feature/PublicReviewApiTest.php`):**
- only `Published` reviews appear; pending/rejected hidden.
- `?seller=`, `?with_images=1`, `?rating=5` each filter correctly.
- summary average/distribution/with_images_count correct; `sellers[]` carries names.
- slug AND uuid both resolve (no 22P02); unknown product → 404.
- batch endpoint returns `{uuid:{average,count}}`; an unreviewed uuid is absent.

**Steps:** controllers → resources → routes → tests → `make check` → commit + push
`feat(reviews): R5 public read api`.

---

## Phase R6 — Session (buyer) API

**`Presentation/Controllers/Api/CustomerReviewController.php`** (customer-auth):
- `eligible(Request $r): JsonResponse` — `?product={idOrSlug}` → resolve uuid →
  `ReviewEligibilityService::eligibleLines((int)$c->getKey(), $c->uuid, $productUuid)` →
  `$this->ok([...])` (each: `order_line_uuid`, `seller:{id,name}` via StoreQueryContract,
  `variant_label`, `product_title`, `purchased_at`).
- `store(SubmitReviewRequest $r): JsonResponse` — build `SubmitReviewDTO` (compute
  `authorName` = masked `first_name + ' ' + first_initial(last_name).'.'` from
  `current_actor()`); `CreateReviewAction::make()->run($dto, $r->file('photos', []))`;
  `return $this->created(new MyReviewResource($review))` — status `pending_review` so
  the UI says "onay bekliyor".
- `mine(): JsonResponse` — `$reviews->forCustomer((int)$c->getKey())` →
  `MyReviewResource::collection`.
- `destroy(string $review): JsonResponse` — `findByUuid` (404), `$this->authorize('delete',
  $model)`, `DeleteReviewAction::make()->run($model)`, `noContent()`.

**`Presentation/Requests/SubmitReviewRequest.php`** (extends `BaseRequest`):
`authorize()` = actor is a Customer; `rules()`:
```php
'order_line_uuid' => ['required','uuid'],
'rating'          => ['required','integer','min:1','max:5'],
'body'            => ['nullable','string','max:2000'],
'photos'          => ['nullable','array','max:6'],
'photos.*'        => ['image','mimes:jpeg,png,webp,avif','max:8192'], // 8MB, matches HasMedia
```
`BatchRatingsRequest`: `authorize()=true` (public), `product_ids: required array max:100`,
`product_ids.* : uuid`.

**`MyReviewResource`** — `{id, product_uuid, product_title (from a stored... see note),
rating, body, status, images, created_at}`. NOTE: the review stores no product title; for
`/mine`, resolve titles in batch via `CatalogQueryContract` (product summaries) — do NOT
import Catalog. If `CatalogQueryContract` lacks a title-by-uuid batch, use
`CatalogBrowseContract::productSummaries([...])` (same call the Offer contributor uses).

**Routes** (in the `['auth:sanctum','throttle:api']` customer group):
```php
Route::get('reviews/eligible', [CustomerReviewController::class, 'eligible'])->name('reviews.eligible');
Route::get('reviews/mine',     [CustomerReviewController::class, 'mine'])->name('reviews.mine');
Route::post('reviews',         [CustomerReviewController::class, 'store'])->name('reviews.store');
Route::delete('reviews/{review}', [CustomerReviewController::class, 'destroy'])->name('reviews.destroy');
```

**Tests (`tests/Modules/Reviews/Feature/CustomerReviewApiTest.php`):**
- `eligible` returns delivered-unreviewed lines; hides already-reviewed ones.
- `store` with a valid line → 201, `status=pending_review`, invisible on the public
  endpoint until published.
- `store` with a not-owned / not-delivered line → 422.
- photos upload attaches media + sets `has_photos`.
- `destroy` deletes own review (204); another customer's → 403/404; the freed line is
  `eligible` again.

**Steps:** controller → requests → resources → routes → tests → `make check` →
commit + push `feat(reviews): R6 buyer api`.

---

## Phase R7 — Moderation UI (Admin/Editor)

**`Presentation/Filament/Resources/ReviewModerationResource.php`** — mirror
`ProductModerationResource`:
- `$model = Review::class`; nav group "Moderasyon" (or the group products use), icon,
  `getModelLabel()`/`getPluralModelLabel()` Turkish.
- `canViewAny()` → `auth()->user()->can('viewAny', Review::class)`; `canCreate/canEdit/
  canDelete/canDeleteAny` → **false**.
- `getNavigationBadge()` → `Review::query()->where('status', ReviewStatus::PendingReview)->count()` (null if 0); badge color.
- `getEloquentQuery()` → `parent::getEloquentQuery()` (no tenancy scope — reads across all
  sellers).
- `table()`: columns (product title via a relation-free accessor or a `store`/`rating`/
  masked author/`created_at`), `SelectFilter::make('status')->default(PendingReview->value)`,
  a `SelectFilter` for `has_photos` (the "sadece resimli" moderation view the owner asked
  for), `->defaultSort('created_at','asc')` (oldest first), actions
  `[ViewAction, self::publishAction(), self::rejectAction()]`, `->bulkActions([])`.
- `infolist()` — read-only: rating, body, the **photo gallery** (so the moderator sees
  what they approve), product, seller, buyer (masked), dates.
- `publishAction()`: visible when `awaitsModeration()` && `can('review.moderate')`,
  `->requiresConfirmation()`, `->action(fn ($record) => self::decide(fn () =>
  app(PublishReviewAction::class)->run($record, new ReviewModerationDTO(moderatedBy:(int)auth()->id())), 'yayınlandı'))`.
- `rejectAction()`: a `Textarea::make('reason')->required()->maxLength(1000)` form, then
  `app(RejectReviewAction::class)->run($record, new ReviewModerationDTO(moderatedBy:(int)auth()->id(), reason:(string)$data['reason']))`.
- `decide(callable, string $msg)`: try/catch `ReviewException` → warning Notification;
  else success Notification.

**Register** in `app/Providers/Filament/AdminPanelProvider.php` `->resources([...])`:
add `ReviewModerationResource::class`.

**`RolePermissionSeeder`** — attach `review.view_any`, `review.view`, `review.moderate`
to **Admin + Editor** roles (Super Admin bypasses). Run `make permissions` first so the
names exist.

**Tests (`tests/Modules/Reviews/Feature/ReviewModerationAccessTest.php`):**
- a Seller / Seller Employee cannot reach the resource or the moderate ability (403).
- an Admin and an Editor can; publishing a pending review moves it to Published and it
  then appears on the public endpoint.

**Steps:** resource → register → seeder → `make permissions` → tests → `make check` →
commit + push `feat(reviews): R7 moderation ui`.

---

## Phase R8 — Hardening, docs, freeze note

- Full boundary sweep: `LayeringTest`, `ConventionsTest` (DTO suffix, strict_types),
  `CatalogBoundaryTest` unaffected. Confirm no `App\Modules\*` import leaked into Reviews.
- **Security test** (`tests/Modules/Reviews/Feature/`): the gate cannot be bypassed by
  posting a forged `order_line_uuid`; the seller tag on a stored review always matches the
  order line, never the request.
- Update `docs/modules/Reviews.md` status → **COMPLETE** (date), with any recorded
  deviations from this spec (report them, do not silently diverge).
- Update the "Current state" block in `CLAUDE.md` (Reviews complete; the newest module;
  imports no module; Questions still to come).
- Update `app/Modules/README.md` index.
- `make check` green.
- Commit + push `feat(reviews): R8 hardening + docs`.

---

## R9 — Storefront (DESKTOP session — NOT this work order)

For agreement only; the desktop session builds this after R1–R8 land. The API contract:

- `GET /api/v1/products/{idOrSlug}/reviews?seller=&with_images=1&rating=5&page=`
  → `data: [PublicReview]`, `meta: {pagination…, summary: {average, count,
  distribution:{5,4,3,2,1}, with_images_count, sellers:[{id,name,count}]}}`.
  `PublicReview = {id, rating, body, author_name, seller:{id,name}, variant_label,
  images:[{thumb,preview,large}], created_at}`.
- `POST /api/v1/products/ratings` `{product_ids:[uuid]}` → `{ratings:{uuid:{average,count}}}`.
- `GET /api/v1/reviews/eligible?product={idOrSlug}` (auth) → `[{order_line_uuid,
  seller:{id,name}, variant_label, product_title, purchased_at}]`.
- `POST /api/v1/reviews` (auth, multipart) `{order_line_uuid, rating, body?, photos[]?}`
  → 201 `{id, status:'pending_review', …}`.
- `GET /api/v1/reviews/mine` (auth) → `[{id, product_uuid, product_title, rating, body,
  status, images, created_at}]`.
- `DELETE /api/v1/reviews/{review}` (auth) → 204.

Money is decimal strings; `average` is a decimal string (`"4.3"`), never parsed. The
storefront adds: a product-page reviews section (★ summary + distribution + seller &
image-only filters + cards), listing-card `★ 4.3 (128)` badges (batch endpoint), and a
"Değerlendir" form on delivered lines in Siparişlerim.
