# Reviews

**Status: COMPLETE (2026-08-07; ADR-066–069, built R0–R8).** The design below is
what shipped; §13 records where the build deviated from it and why. The phased
work order was `BUILD_REVIEWS.md`. **Not frozen** — the storefront (§9) is still
to come, and Questions will reuse these patterns. Reviews is the platform's
first module built purely to carry **user-generated content about the catalogue**,
and the first whose records a stranger reads on a public page.

A Review is one buyer's **rating (1–5) + optional text + optional photos** of a
**product they were delivered**, published **only after moderation**. The catalogue
is shared — one product, many sellers (ADR-037) — so a review is written about the
**product**, and carries the **seller it was bought from** as a tag, derived from
the order, never chosen by the buyer.

**Questions ("Satıcıya Sor") are a SEPARATE module** (owner decision 2026-08-06) —
built after this one, sharing this module's patterns but none of its tables.

---

## 1. What Reviews is — and is not

**Reviews IS:** a purchaser-only, star-rated, optionally-photographed, admin-moderated
opinion attached to a shared catalogue product; the product's rating **summary**
(average, count, distribution) computed from published reviews; and the read
surfaces that put both in front of a shopper (the product page, listing-card
badges) and in front of a moderator (a Filament review queue).

**Reviews is NOT** a seller/store rating (a review is about the PRODUCT, tagged
with the seller — ADR-066; a standalone merchant score is a future module), NOT
Questions & Answers (a separate module), NOT a comment thread or a discussion,
NOT helpful-votes or seller replies (both YAGNI-cut from v1, §11), and NOT a media
host (photos ride the shared `HasMedia` trait, §4).

**Reviews imports NO module** — the platform's strict boundary (ADR-002 /
`LayeringTest`). It reads **Catalog** (does the product exist, its title) and
**Order** (was this buyer delivered this product, and from whom) through **Core
contracts** only, attaches photos through the shared `App\Shared\Traits\HasMedia`
trait (never the Media module), and announces its own lifecycle by domain event.
`LayeringTest` fails the build on any cross-module import, both directions.

---

## 2. The rule that shapes everything here

> A review is written about the **PRODUCT** (the shared catalogue entry), and it
> carries the **seller it was purchased from** as an attribute the buyer never
> types. One product accumulates every seller's buyers' reviews; the rating
> average is the product's; "bu satıcıdan alanlar ne demiş" is a **filter** on
> that one set, not a separate set. (ADR-066)

The seller tag is **authoritative**: it is copied from the delivered order line, so
a buyer cannot attribute their review to a seller they did not buy from, and a
seller cannot be praised or damned by a review of a purchase that was never theirs.

---

## 3. The aggregate: what a review is bound to (ADR-067)

A review is bound to **one delivered order line**, and that binding is the whole
integrity model:

- **`order_line_uuid` is UNIQUE.** One delivered line → at most one review. A buyer
  who bought the same product in **two** orders may write **two** reviews (owner
  decision) — because each is a distinct purchase experience — and the uniqueness
  sits on the line, not on `(customer, product)`, to allow exactly that while still
  refusing a second review of the *same* purchase.
- **The gate is delivery, not payment.** Only an `OrderStatus::Delivered` line
  qualifies (ADR-064's inferred delivery). "Kullandım, şöyleymiş" is the honesty a
  review promises; a paid-but-unshipped order has no experience to report yet.
- **The seller tag, the variant and the purchase date are copied from the line** at
  creation, so the review is a self-contained snapshot the way an order line is
  (ADR-053) — a later re-pricing, a store rename or a returned parcel never rewrites
  what a past review says it was bought from.

Because Reviews imports no module, it cannot read `orders`/`order_lines`. It asks
one new Core method (§5) which delivered lines a buyer holds for a product, subtracts
the ones it has already turned into reviews, and offers the remainder as
"reviewable purchases".

---

## 4. Photos: the shared trait, moderated as part of the review (ADR-068)

A review's photos use **`App\Shared\Traits\HasMedia`** and its `images` collection
(public disk, jpeg/png/webp/avif, responsive `thumb`/`preview`/`large`), exactly as
Catalog product images do — **not** the Media module, which stays on the forbidden
list. Zero to N photos; photos are optional, a rating is not.

**There is no separate photo-moderation step.** The Media service validates only
type and size; it has no approve/reject flow, and inventing one would be a second
moderation queue for one decision. A review with its photos is held as **one unit**
in `PendingReview` and published or rejected whole — if a photo is unacceptable, the
review is rejected. This is the one place the design deliberately does **less** than
it could: per-photo moderation is a real feature and explicitly not v1.

---

## 5. The purchase gate: one new Core method on `OrderQueryContract` (ADR-067)

Order owns orders and the delivered state, so the read lives on **its** existing
Core port rather than a new one:

```
OrderQueryContract::deliveredPurchaseLines(string $customerUuid, string $productUuid): array
    // → list of {order_line_uuid, store_uuid, selling_org_uuid, variant_uuid,
    //            variant_label, product_title, purchased_at}
    // Delivered lines only. Empty when the buyer has none.
```

> **As built:** the customer crosses as a **uuid**, not an internal id, and the
> date is **`purchased_at`**, not `delivered_at` — there is no such column on an
> order. Both are recorded in §13 and in the `001_Architecture.md` amendment log.

- **Keyed by (customer, product)** — the question Reviews actually asks, which no
  existing method answered (every current `OrderQueryContract` method is
  order-uuid-centric). Added to the port Order already implements; Reviews depends
  on the Core interface and never on Order (`LayeringTest`).
- **Returns lines, not a boolean**, precisely because the aggregate is per-line
  (§3): the eligibility screen has to show a buyer *which* purchase it is offering
  to review, and the seller tag it will stamp comes from here.
- **A purchase date rides along** so a "you can review purchases from the last N
  days" window is a later `settings()` tweak, not a schema change. v1 sets no
  expiry. It is the ORDER's `placed_at`: delivery on an order is a status and
  nothing more, and the delivery timestamp lives on Shipping's `shipments`, which
  neither Order nor Reviews may read. The field is named after what it is (§13).

This is a **read added to a frozen-by-convention contract a later module requires** —
recorded in the `001_Architecture.md` amendment log, the same footing as the reads
Offer and the store-page work added to `StoreQueryContract`.

---

## 6. Moderation: published only after approval (ADR-068)

The owner's hard requirement: **a review appears only after it is approved, and the
approver is never the seller.** So this is **pre-moderation**, and it reuses
Catalog's product-moderation pattern verbatim — status on the entity, a
read-and-decide Filament queue, verdict actions emitting events.

- **`ReviewStatus` enum** (no `Enum` suffix, ADR-007): `PendingReview` →
  `Published` | `Rejected`. **No `NeedsRevision`** — a buyer does not iterate on a
  review the way a seller iterates on a product listing; a review is good enough to
  publish or it is not.
- **A new review is born `PendingReview`.** It is invisible on every public surface
  until a moderator publishes it. A `Rejected` review never appears and is never
  counted in the summary.
- **The moderators are Admin + Editor** (owner decision), via a dedicated
  `review.moderate` ability; **Super Admin bypasses** every policy already. Editor
  is the platform's content role, which is what review text and photos are. The
  seller has **no** lever here — not to approve, not to hide, not to reject — by
  design.
- **`ReviewModerationResource`** (Filament, admin panel): the same shape as
  `ProductModerationResource` — `canCreate/Edit/Delete = false`, default filter
  `PendingReview`, oldest-first, a sidebar badge counting the queue, an **Approve**
  action and a **Reject** action that requires a `reason` (kept for the internal
  record; the buyer is not shown it in v1). Registered in the composition root
  (`AdminPanelProvider`), the one sanctioned cross-module reference.
- **Verdict actions** (`PublishReviewAction`, `RejectReviewAction`) extend
  `BaseAction`, stamp `status`/`moderated_at`/`moderated_by`/`moderation_reason`, and
  emit `ReviewPublished` / `ReviewRejected` in `after()`. `ReviewSubmitted` fires on
  creation (a hook for a future "N reviews waiting" notification — not built now).

---

## 7. The public read surfaces (ADR-069)

The public **product page has no server-side assembler** — it is composed by the
Next.js storefront from several endpoints (content, offers, prices), which is the
composition ADR-058 chose. The `StorefrontContributorContract` seam is **store-page
level**, not product-page level. So Reviews adds its own endpoints, mirroring how
Offer added the buy box, rather than contributing to a page assembler that does not
exist:

- **`GET /api/v1/products/{idOrSlug}/reviews`** — published reviews (paginated) **+
  a `summary`** `{average, count, distribution: {5,4,3,2,1}, with_images_count}`.
  Filters: **`?seller={storeUuid}`** (the required seller filter — reads the tag from
  §3), **`?with_images=1`** (the required image-only filter), `?rating=5`, and a sort
  (newest first; no "most helpful" because there are no votes in v1). Unauthenticated,
  `throttle:storefront`, uuid-or-slug resolved through `CatalogBrowseContract` — the
  same slug-resolve the offers endpoint does, so a slug never hits a uuid column (the
  22P02 trap the platform has been bitten by repeatedly).
- **`POST /api/v1/products/ratings`** — a **batch** rating summary for listing/search
  cards, keyed `{productUuid: {average, count}}`, mirroring `POST /offers/prices`. One
  call prices a whole grid's stars instead of one query per card on the busiest
  anonymous route. An unknown/unreviewed product is simply absent (never `0.0`, which
  would read as "rated zero").

Both are **read models over published reviews only**; the average and distribution
are **computed on read**, not stored (the same discipline as the buy box, ADR-045).
If aggregate reads ever get hot, a denormalised counter is a later optimisation
behind these same endpoints — the shape does not change.

---

## 8. The session (buyer) surfaces

Authenticated customers only (the storefront's customer guard):

- **`GET /api/v1/reviews/eligible?product={idOrSlug}`** — the buyer's delivered lines
  for this product that they have **not yet reviewed**: `deliveredPurchaseLines(...)`
  minus this module's own `order_line_uuid`s. Each entry carries the seller tag and
  purchase date, so the "Değerlendir" screen can say *which* purchase it is about.
- **`POST /api/v1/reviews`** — create one. Body: `order_line_uuid`, `rating` (1–5,
  required), `body` (optional), `photos` (optional, multipart). The server
  **re-verifies** the line is the caller's, delivered, and unreviewed — the client's
  eligibility read is a convenience, never the authority. Born `PendingReview`;
  returns 201 with status so the UI says "onay bekliyor", not "yayınlandı".
- **`GET /api/v1/reviews/mine`** — the buyer's own reviews across all statuses (so
  they can see a pending one). A buyer **may delete their own review** (owner
  decision) — no edit; a mistaken review is deleted and, if they wish, written again
  from the still-eligible line. Deletion is a **hard delete** (a review is not an
  audit record — the append-only rule is Audit/Activity's, not this module's), which
  frees the `order_line_uuid` so the line is eligible again; the partial UNIQUE has
  no soft-deleted ghost to collide with. Deleting a published review updates the
  summary on next read (nothing is stored to invalidate).

Names are **masked** on every public surface: "Abdullah Ç." — first name, last
initial. The buyer's identity is never a public field.

---

## 9. Storefront (Next.js, desktop session — a separate build step)

- **Product page**: a reviews section under the buy box — the ★ summary + a
  distribution bar chart, the **seller filter** and the **"sadece resimli"** toggle,
  and the review cards (masked name, stars, date, seller tag, text, photo gallery).
- **Listing & search cards**: a compact **`★ 4.3 (128)`** badge fed by the batch
  endpoint (§7), alongside the price badge already there.
- **Siparişlerim**: on a **delivered** order's lines, a **"Değerlendir"** control →
  a rating + text + photo form (`POST /reviews`) → "Değerlendirmeniz onaya
  gönderildi". An already-reviewed line shows its status instead.

---

## 10. Boundaries and tests

- **`LayeringTest`** gains two `arch()` blocks (written out, no loops): Reviews
  imports no other module (Catalog, Order, Store, Offer, Inventory, Payment,
  Shipping, Organization, Media all forbidden — reached only via Core contracts and
  class-string events); and no module depends on Reviews (`toOnlyBeUsedIn` Reviews +
  `App\Providers\Filament` + its database/test namespaces). `Reviews\Domain` joins
  the "no cache/request/encrypt in Domain" list.
- **`OrderQueryContract` impl** (`OrderQuery`) gains `deliveredPurchaseLines` — an
  exists/select join `orders`→`order_lines` on `customer_id`, `product_uuid`,
  `status = Delivered`. Order's own tests cover it; Reviews tests use a fake of the
  Core contract.
- **Purchase-gate security test**: a customer cannot review a product they were not
  delivered, cannot review another customer's line, and cannot review the same line
  twice — asserted at the action, not only the request.
- **Moderation isolation test**: a seller (and a seller employee) cannot reach the
  moderation ability or resource; only Admin/Editor/Super Admin can.
- **Money**: there is none in this module, so the minor-units rule does not apply —
  a `rating` is a small integer, not a price.

---

## 11. Deliberately not in v1 (YAGNI, stated so it is a decision not an omission)

- **Helpful / unhelpful votes** on a review, and any "most helpful" sort.
- **Seller replies** to a review (a seller answering a review is close to Questions;
  it belongs with that module's thinking, if at all).
- **Review editing** — delete-and-rewrite instead (§8).
- **Per-photo moderation** — the review is the moderation unit (§4).
- **A standalone seller/store rating** — reviews are product-attributed (ADR-066); a
  merchant score is a future module.
- **A review-window expiry** — any delivered line stays reviewable; `delivered_at`
  is carried so a `settings()` window is a later tweak (§5).
- **Notifications** to moderators or buyers — `ReviewSubmitted`/`ReviewPublished`
  are emitted as hooks, but no listener ships in v1.

---

## 12. What shipped, and where to look

| Area | Where |
|---|---|
| `ReviewStatus` — three cases, no `NeedsRevision` (§6) | `Domain/Enums/ReviewStatus` |
| The aggregate — snapshot tag, masked author, `has_photos` flag (§3) | `Domain/Models/Review` |
| `order_line_uuid` UNIQUE — the whole integrity model (ADR-067) | `database/Modules/Reviews/migrations/*_create_reviews_table` |
| The read model: computed average, distribution, batch summaries (§7) | `Infrastructure/Repositories/ReviewRepository` |
| The purchase gate on the Core Order port (§5) | `Core\Domain\Contracts\OrderQueryContract::deliveredPurchaseLines` |
| Delivered-minus-already-reviewed, in one place (§8) | `Application/Services/ReviewEligibilityService` |
| The gate closed server-side + the seller tag copied (ADR-066) | `Application/Actions/CreateReviewAction` |
| The two verdicts (§6) | `Application/Actions/{Publish,Reject}ReviewAction` |
| Delete-and-rewrite, hard (§8) | `Application/Actions/DeleteReviewAction` |
| Ownership-only delete, seller-free moderation (§6) | `Presentation/Policies/ReviewPolicy` |
| `GET /products/{idOrSlug}/reviews` + `POST /products/ratings` (§7) | `Presentation/Controllers/Api/Storefront/*` |
| `GET /reviews/eligible`, `POST /reviews`, `GET /reviews/mine`, `DELETE /reviews/{uuid}` (§8) | `Presentation/Controllers/Api/CustomerReviewController` |
| The queue, oldest-first, photos on screen (§6) | `Presentation/Filament/Resources/ReviewModerationResource` |

**Tests:** `ReviewStatusTest` (the enum's absent fourth case), `ReviewRepositoryTest`
(published-only arithmetic), `DeliveredPurchaseLinesTest` (Order's side of the gate),
`ReviewGateTest` (the action against a faked port), `PublicReviewApiTest`,
`CustomerReviewApiTest` (end to end through the real port),
`ReviewModerationAccessTest` (the seller has no lever), and **`ReviewGateSecurityTest`**
— the adversarial pass, over HTTP, that a forged line, a forged seller tag, a forged
status and a forged author name all fail.

---

## 13. Deviations from this specification, recorded

Three, all reported rather than taken silently (ADR-018).

**1. `deliveredPurchaseLines()` takes a customer UUID, not an internal id** (§5).
`orders` carries and indexes `customer_uuid`, Reviews stores both halves of the
ADR-040 pair, and a port satisfiable without an internal id should be. This does
not make `OrderQueryContract` uuid-only in general — `orderFulfilment()` returns a
`customer_id` deliberately, to be compared against the authenticated actor.

**2. It returns `purchased_at`, not `delivered_at`** (§5). There is no
`delivered_at` on an order: delivery is the STATUS alone, and the timestamp lives
on Shipping's `shipments` — a table Order must not read and Reviews may not
either. The field carries the order's `placed_at` and is **named after what it
is**, because a caller told "delivered_at" would eventually build a review window
on a date that is really a purchase date. v1 has no window, so nothing depends on
the difference yet — which is the only comfortable moment to get the name right.

**3. `POST /api/v1/reviews` also requires `product`** (§8, and `BUILD_REVIEWS.md`
§R9's contract sketch omits it). The gate is keyed on (customer, PRODUCT) by
ADR-067's own design: `deliveredPurchaseLines()` cannot be asked "which purchase
is this line?", and Reviews may not read `order_lines` to find out — so the server
literally cannot locate the line without it. It weakens nothing: the product is
not trusted, it only selects which of the buyer's delivered lines to check, and a
forged one yields no match and a refusal. The storefront is standing on the
product page when it renders the form.

**One further note, not a deviation:** `ReviewPolicy::delete()` overrides
`BasePolicy`, which checks a permission before ownership. That is right for
admin-scoped resources and exactly wrong here — the only actor who may delete a
review is the customer who wrote it, and customers hold no `review.*` permission
at all. So the check is ownership alone. A consequence worth stating: **removing a
PUBLISHED review is not a v1 operation for anybody but its author** (rejection is
only open while it is pending).

---

## 14. ADRs

- **ADR-066** — Reviews are product-attributed with a seller tag; the tag is copied
  from the delivered order line, never chosen by the buyer; rating aggregates to the
  product. Questions are a separate module.
- **ADR-067** — The review binds to one delivered order line (`order_line_uuid`
  UNIQUE); one review per delivered purchase, so a repeat purchase earns a repeat
  review; the gate is delivery, not payment; `OrderQueryContract` gains
  `deliveredPurchaseLines`.
- **ADR-068** — Pre-moderation: a review is published only after approval by
  Admin/Editor (never the seller); status on the entity (`PendingReview` →
  `Published`/`Rejected`, no `NeedsRevision`); photos are moderated as part of the
  review, not separately.
- **ADR-069** — Reviews compose the product page through dedicated public endpoints
  (there is no product-page assembler); the rating summary is computed on read; a
  batch ratings endpoint feeds listing-card badges.
