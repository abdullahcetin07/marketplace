# BUILD — Questions module ("Satıcıya Sor", feedback #6)

**Spec:** `docs/modules/Questions.md` (ADR-070–071). Read it first — this work order
implements exactly that. **Frontend (the Next.js storefront Q&A UI) is NOT in this
work order** — that is the desktop session's job once these endpoints land; §Q9
records the API contract both sides build to.

**What Questions is:** a signed-in shopper's public question about a **product**,
directed at the **buy-box seller** (server-derived + snapshotted, never chosen by the
client), which **that seller answers** from the seller panel; the answered pair is
public. **Anyone signed in may ask** (no purchase gate). Moderation is **reactive** —
the seller's answer publishes it, an admin **hides** an unacceptable one after the
fact. It is the mirror-image of Reviews, and **mirrors Reviews' file structure** — copy
that module's shape (`app/Modules/Reviews/`).

Build the phases **in order**. Each phase ends with `make check` green and a commit +
push. Do not build ahead. If anything here contradicts the spec or an existing decision,
**STOP and report** (ADR-018) — do not pick a side silently.

---

## Non-negotiables (restated because they bite here)

- **`declare(strict_types=1)`** in every PHP file. No `dd/dump/var_dump/die/exit`.
- **Questions imports NO module.** It reaches Catalog, Offer, Store, Organization
  **only** through `app/Core/Domain/Contracts` and cross-module events **by
  class-string**. `LayeringTest` fails the build on any import, both directions.
- **UUID public, internal `id` never leaves the app** (#7). Route-model binding by uuid
  (the `HasUuid` trait). No `HasMedia` — Questions has no photos.
- **No money** — no rating, no price. The minor-units rule does not apply.
- **No `cache()`/`request()`/`encrypt()` in Domain** (ADR-019). `now()`/`config()` OK.
- **DTOs** carry the `DTO` suffix in `Questions/Domain/DTOs/` (ADR-021). **Enums** carry
  no `Enum` suffix (ADR-007).
- **Permissions** via `PermissionRegistry`; never hand-write names; `make permissions`.
- **`make check` green before any phase is "done".**

---

## Verified facts (so you don't re-derive them)

- **Buy-box seller:** `OfferQueryContract::featuredOfferForProduct(string $productUuid): ?array`
  → the winner, shape includes `selling_org_uuid` + `store_uuid` (+ uuid, prices, …).
  **Null when nothing is sellable** — the ask is refused (§Q3). `app/Core/Domain/Contracts/OfferQueryContract.php`.
- **Slug-or-uuid product resolve:** `CatalogBrowseContract::publishedProductUuidFor($idOrSlug): ?string`
  (null → 404, never leak). Product titles for `/mine`: `CatalogBrowseContract::productSummaries([$uuid]) : array` keyed by uuid.
- **Store names:** `StoreQueryContract::publicProfilesFor(array $storeUuids): array` → `uuid => {name, city, slug}` (live only).
- **Seller-panel tenancy** (no Filament tenant, ADR-030): scope by store uuid —
  `OrganizationAuthorizationContract::organizationIdsForUser((int) auth()->id()): array` (internal org ids)
  → `StoreQueryContract::liveStoresForOrganization(int $orgId): array` (`uuid => name`) → collect store uuids →
  `whereIn('store_uuid', $storeUuids)`. This is exactly `OrderResource::sellerStoreUuids()` in
  `app/Modules/Order/Presentation/Filament/Seller/Resources/OrderResource.php`.
- **UserType** has only `Admin`, `Seller`, `Customer` (`app/Shared/Enums/UserType.php`). Editor is an admin-guard ROLE.
- Traits: `App\Shared\Traits\HasUuid` (auto-uuid, route key = uuid). Base classes (`app/Core`):
  `BaseAction` (override `handle()`, call via `->run()`; `after()` hook), `BasePolicy`
  (implement `permissionPrefix()`, override `owns()` — default false), `BaseResource`
  (`publicId()`), `BaseController` (`ok()/created()/paginated()/perPage()/noContent()`),
  `BaseRequest` (`authorize()` default **false**; `rules()`; `actor()`).
- Routes (`routes/api.php`, under `prefix('v1')->name('api.v1.')->middleware(SetLocale)`):
  public storefront group = `->middleware('throttle:storefront')` (declare specific paths BEFORE
  `products/{product}`); customer-auth group = `->middleware(['auth:sanctum','throttle:api'])`.
- Filament: seller resources register in `app/Providers/Filament/SellerPanelProvider.php`
  `->resources([...])`; admin in `AdminPanelProvider.php`. Seller-panel action-with-form pattern:
  `app/Modules/Order/Presentation/Filament/Seller/Resources/CancellationRequestResource.php`
  (`approveAction`/`rejectAction`: `Action::make()->form([Textarea])->visible(can(...))->action(fn => app(X)->run(...))` + `Notification`).
- Tests: **Pest**; Feature/Modules get `RefreshDatabase`. `tests/TestCase.php` helpers:
  `seedPlatform()`, `seedRolesAndPermissions()`, `actingAsCustomer()`, `actingAsSeller()`,
  `actingAsAdmin()`, `grant(User, ...permissions)`. Module tests under `tests/Modules/Questions/Feature/`.
- **Template module:** `app/Modules/Reviews/` — copy its tree (§Q0). Greenfield: no `questions` table/model exists.

---

## Phase Q0 — Scaffold & boundary

**Files:**
- Create `app/Modules/Questions/QuestionsServiceProvider.php` — `register()` (bind
  `QuestionRepositoryContract` → `QuestionRepository` singleton; the `PermissionRegistry`
  lines below), `boot()` (`loadMigrationsFrom(database_path('Modules/Questions/migrations'))`,
  `Gate::policy(Question::class, QuestionPolicy::class)`).
- Register the provider in `bootstrap/providers.php` **after** Reviews' provider and
  **before** the two Filament panel providers (same slot Reviews uses).
- Create `app/Modules/Questions/README.md`; modify `app/Modules/README.md` (index).
- Modify `tests/Architecture/LayeringTest.php` — two arch blocks + Domain forbidden entry.
- Create the dir skeleton mirroring `app/Modules/Reviews/` (Application/{Actions,Services},
  Domain/{Contracts,DTOs,Enums,Events,Exceptions,Models}, Infrastructure/Repositories,
  Presentation/{Controllers/Api/{Storefront},Filament/{Resources,Seller/Resources},Policies,Requests,Resources}).

**Permissions (in `register()`):**
```php
PermissionRegistry::resource('question', [UserType::Admin, UserType::Seller]); // view_any/view for both panels
PermissionRegistry::ability('question.answer', [UserType::Seller]);            // ONLY the seller answers (ADR-071)
PermissionRegistry::ability('question.moderate', [UserType::Admin]);           // hide/un-hide (reactive)
```

**LayeringTest additions (written out, no loops):**
```php
arch('Questions imports no other module')
    ->expect('App\Modules\Questions')
    ->not->toUse([
        'App\Modules\Catalog', 'App\Modules\Order', 'App\Modules\Store',
        'App\Modules\Offer', 'App\Modules\Inventory', 'App\Modules\Payment',
        'App\Modules\Shipping', 'App\Modules\Organization', 'App\Modules\Reviews',
        'App\Modules\Media', 'App\Modules\Identity', 'App\Modules\Notification',
    ]);

arch('no module depends on Questions')
    ->expect('App\Modules\Questions')
    ->toOnlyBeUsedIn([
        'App\Modules\Questions', 'App\Providers\Filament',
        'Database\Modules\Questions', 'Tests\Modules\Questions',
    ]);
```
Add `App\Modules\Questions\Domain` to the "no cache/request/encrypt in Domain" list.

**Steps:** create → `make check` (LayeringTest green, boots) → commit + push `feat(questions): Q0 scaffold + boundary`.

---

## Phase Q1 — Domain

**`Domain/Enums/QuestionStatus.php`** — `enum QuestionStatus: string` (use `HasEnumHelpers`):
```php
case Pending  = 'pending';
case Answered = 'answered';
// helpers: isPending(): bool; isAnswered(): bool; label(): string; color(): string;
```
Hiding is NOT a status — it is the `hidden_at` flag (§Q2). Visibility = `Answered && hidden_at === null`.

**`Domain/Models/Question.php`** — `final class Question extends Model` (NO media):
```php
use HasFactory, HasUuid;
protected $fillable = [
  'product_uuid','customer_id','customer_uuid','asker_name','store_uuid','selling_org_uuid',
  'body','status','answer_body','answered_at','answered_by','hidden_at','hidden_by','hidden_reason',
];
protected function casts(): array {
  return ['status' => QuestionStatus::class,
          'answered_at' => 'immutable_datetime', 'hidden_at' => 'immutable_datetime'];
}
// scopes: scopePublic($q) => where('status', Answered)->whereNull('hidden_at');
//         scopeForProduct($q, string $productUuid); scopeVisibleToSeller($q) => whereNull('hidden_at');
protected static function newFactory() { return \Database\Modules\Questions\Factories\QuestionFactory::new(); }
```

**`Domain/Contracts/QuestionRepositoryContract.php`:**
```php
public function create(array $attributes): Question;
public function findByUuid(string $uuid): ?Question;
/** Answered + not hidden, filtered by optional seller store, paginated newest-first. */
public function publicForProduct(string $productUuid, QuestionListFilterDTO $filter): LengthAwarePaginator;
/** All statuses, one asker, newest first. */
public function forCustomer(int $customerId): Collection;
public function delete(Question $question): void;
```

**`Domain/DTOs/`:**
- `AskQuestionDTO` — `productUuid, body, customerId (int), customerUuid, askerName` (masked; computed in controller). `readonly`. **No store/seller** — the action snapshots them from the featured offer (ADR-070).
- `AnswerQuestionDTO` — `answerBody, answeredBy (int)`. `readonly`.
- `HideQuestionDTO` — `hiddenBy (int), reason (string)`. `readonly`.
- `QuestionListFilterDTO` — `sellerStoreUuid (?string), page (int=1), perPage (int=20)`. `readonly`.

**`Domain/Events/`** — `QuestionAsked`, `QuestionAnswered` (ids + uuids only, no models), extending
`App\Core\Domain\Events\BaseEvent`. E.g. `QuestionAnswered(int $id, string $uuid, string $productUuid, string $storeUuid, int $answeredBy)`.

**`Domain/Exceptions/QuestionException.php`** (extends `BaseException`, `$reportable=false`):
`noSeller()` (422, "Bu ürünü şu an satan bir mağaza yok, soru iletilemedi."), `productNotFound($idOrSlug)` (404),
`notPending()` (409, answering a non-pending question).

**Steps:** write → a unit test of `QuestionStatus` helpers + model casts → `make check` → commit + push `feat(questions): Q1 domain`.

---

## Phase Q2 — Infrastructure: migration + repository + factory

**`database/Modules/Questions/migrations/*_create_questions_table.php`:**
```php
$table->bigIncrements('id');
$table->uuid('uuid')->unique();
$table->uuid('product_uuid');
$table->unsignedBigInteger('customer_id');           // asker (internal id, for /mine scope)
$table->uuid('customer_uuid');
$table->string('asker_name');                        // masked snapshot "Abdullah Ç."
$table->uuid('store_uuid');                           // TARGET seller store (snapshot, ADR-070) + seller tenancy
$table->uuid('selling_org_uuid');                     // target org (payload/display)
$table->text('body');                                 // the question
$table->string('status', 20)->default('pending');
$table->text('answer_body')->nullable();
$table->timestampTz('answered_at')->nullable();
$table->unsignedBigInteger('answered_by')->nullable();// seller user id
$table->timestampTz('hidden_at')->nullable();         // reactive moderation (reversible)
$table->unsignedBigInteger('hidden_by')->nullable();
$table->text('hidden_reason')->nullable();
$table->timestampsTz();
$table->index(['product_uuid','status']);            // public list
$table->index(['store_uuid','status']);              // seller panel
$table->index('customer_id');                        // /questions/mine
$table->index('status');
```

**`Infrastructure/Repositories/QuestionRepository.php`** — implements the contract. `publicForProduct`
= `Question::forProduct($uuid)->public()` + optional `where('store_uuid', $filter->sellerStoreUuid)`,
`orderByDesc('created_at')`, `paginate($filter->perPage)`.

**`database/Modules/Questions/Factories/QuestionFactory.php`** — `definition()` invented uuids; states
`pending()`, `answered()` (sets answer_body/answered_at/answered_by, status Answered), `hidden()`,
`forProduct(string $uuid)`, `forStore(string $uuid)`, `forCustomer(int $id, string $uuid)`.

**Tests (`tests/Modules/Questions/Feature/QuestionRepositoryTest.php`):**
- `publicForProduct` returns only Answered + non-hidden; excludes pending and hidden-answered.
- the seller-store filter narrows correctly.
- `forCustomer` returns all statuses for that asker.

**Steps:** migration → repo → factory → tests → `make check` → commit + push `feat(questions): Q2 migration + repository`.

---

## Phase Q3 — Application actions + policy

**`Application/Actions/AskQuestionAction.php`** (extends `BaseAction`,
`handle(AskQuestionDTO $dto): Question` via variadic like Reviews' `CreateReviewAction`):
1. `$featured = app(OfferQueryContract::class)->featuredOfferForProduct($dto->productUuid);` — inject the contract in the constructor, do not resolve inline in real code (this line shows intent).
2. `if ($featured === null) throw QuestionException::noSeller();`
3. Create the question `status = Pending`, snapshotting `store_uuid` + `selling_org_uuid`
   **from `$featured`** (authoritative, ADR-070), never from input.
4. `after()`: `QuestionAsked::dispatch(...)`.

**`Application/Actions/AnswerQuestionAction.php`** (`handle(Question $q, AnswerQuestionDTO $dto): Question`):
guard `$q->status->isPending()` else `QuestionException::notPending()`; set `status=Answered`,
`answer_body=$dto->answerBody`, `answered_at=now()`, `answered_by=$dto->answeredBy`; `after()`:
`QuestionAnswered::dispatch(...)`.

**`Application/Actions/HideQuestionAction.php`** (`handle(Question $q, HideQuestionDTO $dto): Question`):
set `hidden_at=now()`, `hidden_by`, `hidden_reason`. **`UnhideQuestionAction`** (`handle(Question $q): Question`):
null the three `hidden_*` columns.

**`Application/Actions/DeleteQuestionAction.php`** (`handle(Question $q): void`) — hard delete.

**`Presentation/Policies/QuestionPolicy.php`** (extends `BasePolicy`):
- `permissionPrefix(): string => 'question'`.
- `owns(User $user, Model $q): bool` = `$q->customer_id === (int) $user->getKey()` — for **delete** (`ownershipRequiredFor() => ['delete']`), so an asker deletes their own even though a Customer holds no `question.*` permission (override the base, as `ReviewPolicy` does).
- `answer(User $user, Question $q): bool` = `$user->can('question.answer')` **AND** the question's
  `store_uuid` is among the user's org's live stores — resolve via
  `OrganizationAuthorizationContract::organizationIdsForUser` + `StoreQueryContract::liveStoresForOrganization`
  (a small private helper on the policy). Belt-and-braces with the seller resource's query scope.
- `moderate(User $user): bool` = `$user->can('question.moderate')`.

**Tests (`tests/Modules/Questions/Feature/QuestionActionsTest.php`)** — bind a **fake**
`OfferQueryContract` returning a featured offer:
- `AskQuestionAction` snapshots the featured offer's `store_uuid`/`selling_org_uuid`, born Pending.
- a product with **no** featured offer → `noSeller` (422).
- `AnswerQuestionAction` moves Pending→Answered + stamps; answering a non-pending → `notPending`.
- `HideQuestionAction`/`UnhideQuestionAction` set and clear `hidden_at`.

**Steps:** actions → policy → exception (Q1) → tests → `make check` → commit + push `feat(questions): Q3 actions + policy`.

---

## Phase Q4 — Public read API

**`Presentation/Controllers/Api/Storefront/PublicProductQuestionController.php`** —
`index(Request $r, string $product): JsonResponse`:
- resolve `$productUuid = app(CatalogBrowseContract::class)->publishedProductUuidFor($product)`;
  null → `QuestionException::productNotFound($product)->withStatus(404)`.
- build `QuestionListFilterDTO` from `?seller`, `?page`.
- `$page = $this->questions->publicForProduct($productUuid, $filter);`
- batch-resolve store names for the page's `store_uuid`s via `StoreQueryContract::publicProfilesFor`.
- `return $this->paginated($page, PublicQuestionResource::collection($page->items()), null);` — no summary (no rating).

**`Presentation/Resources/PublicQuestionResource.php`** — `{id: uuid, asker_name (masked),
body, answer_body, seller: {id: store_uuid, name}, asked_at: created_at, answered_at}`.
**No customer_id/customer_uuid**.

**Route** (`routes/api.php`, `throttle:storefront` group, **before** `products/{product}`):
```php
Route::get('products/{product}/questions', [PublicProductQuestionController::class, 'index'])
    ->name('storefront.product.questions');
```

**Tests (`tests/Modules/Questions/Feature/PublicQuestionApiTest.php`):**
- only Answered + non-hidden appear; pending and hidden are absent.
- `?seller=` filters by target store.
- slug AND uuid resolve; unknown product → 404.
- the payload never contains the asker's customer id/uuid.

**Steps:** controller → resource → route → tests → `make check` → commit + push `feat(questions): Q4 public read api`.

---

## Phase Q5 — Session (asker) API

**`Presentation/Controllers/Api/CustomerQuestionController.php`** (customer-auth):
- `store(SubmitQuestionRequest $r): JsonResponse` — resolve product uuid via `CatalogBrowseContract`
  (404 on miss); build `AskQuestionDTO` (compute `askerName` = masked `first_name + ' ' + initial(last_name).'.'`
  from `current_actor()`); `AskQuestionAction::make()->run($dto)`; `return $this->created(new MyQuestionResource($question, $this->titlesFor([$question])))` (status `pending`).
- `mine(): JsonResponse` — `$this->questions->forCustomer((int) $c->getKey())` → `MyQuestionResource::collection`,
  titles resolved in batch via `CatalogBrowseContract::productSummaries`.
- `destroy(string $question): JsonResponse` — shape-guard the uuid (uuid or 404), `findByUuid`,
  `$this->authorize('delete', $model)`, `DeleteQuestionAction::make()->run($model)`, `noContent()`.

**`Presentation/Requests/SubmitQuestionRequest.php`** (extends `BaseRequest`):
`authorize()` = `$this->actor()?->type === UserType::Customer`; `rules()`:
```php
'product' => ['required', 'string', 'max:255'],   // slug or uuid, resolved in controller
'body'    => ['required', 'string', 'min:5', 'max:1000'],
```

**`Presentation/Resources/MyQuestionResource.php`** — `{id, product_uuid, product_title (from batch
titles), body, status, answer_body, seller: {id: store_uuid}, created_at, answered_at}`.

**Routes** (`['auth:sanctum','throttle:api']` group):
```php
Route::get('questions/mine',    [CustomerQuestionController::class, 'mine'])->name('questions.mine');
Route::post('questions',        [CustomerQuestionController::class, 'store'])->name('questions.store');
Route::delete('questions/{question}', [CustomerQuestionController::class, 'destroy'])->name('questions.destroy');
```

**Tests (`tests/Modules/Questions/Feature/CustomerQuestionApiTest.php`):**
- a signed-in customer who **never bought** the product can still ask (the no-purchase-gate assertion) → 201, `status=pending`, invisible on the public endpoint until answered.
- a product nobody sells → 422 (`noSeller`).
- `mine` lists own questions across statuses with the answer when present.
- `destroy` deletes own (204); another customer's → 403/404.

**Steps:** controller → request → resource → routes → tests → `make check` → commit + push `feat(questions): Q5 asker api`.

---

## Phase Q6 — Seller panel (answer)

**`Presentation/Filament/Seller/Resources/QuestionResource.php`** — seller panel:
- `getEloquentQuery()` → `parent::getEloquentQuery()->whereIn('store_uuid', self::sellerStoreUuids())->whereNull('hidden_at')`
  (a seller never sees a hidden question). `sellerStoreUuids()` = the OrderResource helper copied:
  `organizationIdsForUser` → `liveStoresForOrganization` → store uuids.
- `canViewAny()` → `auth()->user()->can('viewAny', Question::class)`; `canCreate/canEdit/canDelete` → false.
- `getNavigationBadge()` → count of **pending** questions in scope (unanswered questions waiting on this seller); badge color.
- table: product (title accessor or product_uuid), question body (truncated), asker (masked), status, asked date;
  `SelectFilter` status default `Pending`; `->defaultSort('created_at','asc')` (oldest waiting first);
  actions `[ViewAction, self::answerAction()]`; `->bulkActions([])`.
- `answerAction()`: visible when `$record->status->isPending() && auth()->user()?->can('answer', $record) === true`,
  `->form([Textarea::make('answer_body')->required()->maxLength(2000)->rows(4)])`,
  `->action(fn ($record, array $data) => self::decide(fn () =>
     app(AnswerQuestionAction::class)->run($record, new AnswerQuestionDTO(answerBody: (string) $data['answer_body'], answeredBy: (int) auth()->id())), 'Cevabınız yayınlandı.'))`.
- `decide(callable, string)`: try/catch `QuestionException` → warning Notification, else success.
- `infolist()` — read-only: question, asker (masked), product, dates, and the answer once present.

**Register** in `app/Providers/Filament/SellerPanelProvider.php` `->resources([...])`.

**`RolePermissionSeeder`:**
- `seller` role gets `question.*` (incl. `question.answer`) automatically via `forGuard(seller)` — verify, no code needed.
- **`seller_employee`** (explicit allow-list): **append** `question.answer` and
  `...PermissionRegistry::forResource('question', ['view_any','view'])` — answering buyer questions is delegable staff work.
- Run `make permissions` first so the names exist.

**Tests (`tests/Modules/Questions/Feature/SellerQuestionPanelTest.php`):**
- a seller sees/answers only questions whose `store_uuid` is their store; another seller's are unreachable.
- answering moves Pending→Answered and it then appears on the public endpoint.
- a **Seller Employee** can answer; a **Customer** cannot reach the resource.

**Steps:** resource → register → seeder → `make permissions` → tests → `make check` → commit + push `feat(questions): Q6 seller answer panel`.

---

## Phase Q7 — Admin moderation (reactive hide)

**`Presentation/Filament/Resources/QuestionModerationResource.php`** — admin panel, a **separate
class** from the seller resource:
- platform-wide `getEloquentQuery()` (no tenancy scope); `canViewAny()` → `can('viewAny', Question::class)`
  on the admin guard (gated by `question.view_any`); `canCreate/canEdit/canDelete` → false.
- table: product, asker, status, a **hidden** indicator, dates; filters for status + a "gizli mi" filter;
  actions `[ViewAction, self::hideAction(), self::unhideAction()]`.
- `hideAction()`: visible when `$record->hidden_at === null && can('question.moderate')`,
  `->form([Textarea::make('reason')->required()->maxLength(1000)])`,
  `->action(fn ($record, array $data) => app(HideQuestionAction::class)->run($record, new HideQuestionDTO(hiddenBy: (int) auth()->id(), reason: (string) $data['reason'])))` + success Notification.
- `unhideAction()`: visible when `$record->hidden_at !== null && can('question.moderate')`,
  `->requiresConfirmation()`, `->action(fn ($record) => app(UnhideQuestionAction::class)->run($record))`.

**Register** in `app/Providers/Filament/AdminPanelProvider.php` `->resources([...])`.

**`RolePermissionSeeder`** — attach `question.view_any`, `question.view`, `question.moderate` to
**Admin + Editor** (Super Admin bypasses). `admin` gets them via guard; `editor` is an explicit
allow-list — add them there.

**Tests (`tests/Modules/Questions/Feature/AdminQuestionModerationTest.php`):**
- a Seller cannot reach the moderation resource or the `question.moderate` ability (403).
- Admin and Editor can; hiding an answered question removes it from the public endpoint; un-hiding restores it.

**Steps:** resource → register → seeder → tests → `make check` → commit + push `feat(questions): Q7 admin moderation`.

---

## Phase Q8 — Hardening, docs, freeze note

- Boundary sweep: `LayeringTest`, `ConventionsTest` (DTO suffix, strict_types), no `App\Modules\*` import leaked.
- **Target-authority security test**: a stored question's `store_uuid` always equals the featured offer's at ask
  time; a client posting a `store`/`seller` field cannot change it (the request has no such field).
- Update `docs/modules/Questions.md` status → **COMPLETE** (date), with any recorded deviations (report, do not silently diverge).
- Update the "Current state" block in `CLAUDE.md` (Questions complete — the last feedback module; imports no module).
- Update `app/Modules/README.md` index.
- `make check` green. Commit + push `feat(questions): Q8 hardening + docs`.

---

## Q9 — Storefront (DESKTOP session — NOT this work order)

The API contract the desktop session builds to:

- `GET /api/v1/products/{idOrSlug}/questions?seller=&page=` → `data: [PublicQuestion]`, `meta` pagination.
  `PublicQuestion = {id, asker_name, body, answer_body, seller:{id,name}, asked_at, answered_at}`.
- `POST /api/v1/questions` (auth) `{product, body}` → 201 `{id, status:'pending', …}` — server derives + snapshots the seller.
- `GET /api/v1/questions/mine` (auth) → `[{id, product_uuid, product_title, body, status, answer_body, seller:{id}, created_at, answered_at}]`.
- `DELETE /api/v1/questions/{question}` (auth) → 204.

Storefront adds: a product-page "Sorular & Cevaplar" section (answered Q&A cards + seller filter + "Satıcıya Sor"
button → question textarea for a signed-in customer, sign-in prompt otherwise), and a "Sorularım" list under /hesap.
No rating anywhere — this is not Reviews.
