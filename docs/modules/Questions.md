# Questions ("Satıcıya Sor")

**Status: SPEC (2026-08-07; ADR-070–071). Not yet built.** The design below is
approved; the phased work order will be `BUILD_QUESTIONS.md`. Questions is the
second module built to carry user-generated content about the catalogue — the
sibling of Reviews — and it reuses Reviews' patterns while sharing none of its
tables.

A Question is one shopper's public question about a **product**, directed at the
**seller they were looking at** (the buy-box winner, snapshotted), which **that
seller answers** from the seller panel. The answered pair is shown publicly on the
product page. **Anyone signed in may ask** — the point of "Satıcıya Sor" is to ask
*before* buying.

---

## 1. What Questions is — and is not

**Questions IS:** a signed-in shopper's question about a product, targeted at one
seller and answered by that seller; the public Q&A list on a product page; a
seller-panel surface where a merchant answers questions aimed at their store; and
an admin surface that reactively hides an unacceptable one.

**Questions is NOT** a review (that is a delivered-purchase rating — a separate
module; a question needs no purchase and carries no star), NOT a chat or a thread
(one question, one answer — no back-and-forth in v1), NOT a support ticket (it is
public and product-scoped, not a private conversation), NOT pre-moderated (the
seller's answer publishes it; ADR-070), and NOT a place the platform answers for
the seller (admin only hides; ADR-071).

**Questions imports NO module** — the platform's strict boundary (ADR-002 /
`LayeringTest`). It reads **Catalog** (product exists, its title) via
`CatalogBrowseContract`, the **buy-box seller** via `OfferQueryContract`, **store
names** via `StoreQueryContract`, and the **seller's organisation** via
`OrganizationAuthorizationContract` — all Core contracts, never a module import —
and announces its lifecycle by domain event. `LayeringTest` fails the build on any
cross-module import, both directions.

---

## 2. The rules that shape everything here

> **A question is about the PRODUCT and directed at ONE seller — the buy-box
> winner at the moment it is asked, snapshotted from the server, never chosen by
> the asker's client.** (ADR-070)

> **The seller's answer is what publishes it.** An unanswered question is private
> to the seller, the admin and the asker; the moment the target seller answers, the
> pair goes public. Moderation is **reactive** — an admin hides an unacceptable one
> after the fact — the opposite of Reviews' pre-moderation, and deliberately so
> (ADR-070). (Reviews.md §6 is the contrast.)

The target is **authoritative**: the server reads the featured offer and snapshots
its store, so a shopper cannot aim a question at a seller who is not actually
selling the product, and the seller who is asked is the one the shopper was looking
at.

---

## 3. Who may ask — no purchase gate (ADR-070)

**Any authenticated customer may ask.** This is the sharpest difference from
Reviews: a review reports an experience, so it is gated on a delivered purchase; a
question is asked *to decide whether to buy*, so gating it on a purchase would
defeat its purpose. The only requirement is a signed-in customer — enough to
attribute the question and to let the asker find the answer later.

The asker's name is **masked** on every public surface ("Abdullah Ç."), computed
from the actor and stored masked, exactly as Reviews does it.

---

## 4. The target seller: server-derived, snapshotted (ADR-070)

`POST /questions` carries `{product, body}` and **no seller** — the server derives
it:

```
featured = OfferQueryContract::featuredOfferForProduct(productUuid)
  → { selling_org_uuid, store_uuid, ... }  (the buy-box winner)
```

- The question snapshots `store_uuid` + `selling_org_uuid` from the featured offer
  at ask time. A later buy-box change never re-aims a past question.
- **No featured offer → the ask is refused** (422): a product nobody is selling has
  no seller to ask. This is an ordinary state a product page can be in, so it is a
  clean refusal, not an error.
- The client cannot pass a target, so it cannot forge one. If the storefront wants
  to let a shopper ask a *specific* non-featured seller later, that is a future
  enhancement with its own ADR — v1 asks the buy-box seller, which is who the
  shopper is looking at.

---

## 5. The lifecycle: `Pending → Answered`, plus a reactive hide (ADR-070/071)

**`QuestionStatus` enum** (no `Enum` suffix, ADR-007): `Pending` → `Answered`.
Hiding is a **separate, reversible flag** (`hidden_at`/`hidden_by`/`hidden_reason`),
not a third status — because an admin may hide either a pending question (an abusive
one, before any seller sees it) or an answered one (reactive takedown), and may
un-hide, and a boolean models that cleanly where a status enum would tangle it.

- **Public** = `Answered` **AND** `hidden_at IS NULL`. Computed on read; nothing
  denormalised to drift.
- **Pending** (unanswered): visible only to the **target seller**, an **admin**, and
  the **asker**. Never public.
- **Answered**: the seller wrote `answer_body`; `answered_at` + `answered_by` (the
  seller user) are stamped; the pair is public.
- **Hidden**: an admin set `hidden_at`; it drops off every public and seller surface;
  clearing it restores the prior visibility.

There is **no seller decline** and **no admin answer** in v1 (§9): a seller answers
or leaves it pending, and an admin moderates but does not speak for the seller.

---

## 6. The seller answers — seller panel, org-scoped (ADR-071)

The target seller answers from the **seller Filament panel**, and tenancy is the
same per-resource query scope every seller surface uses (ADR-030) — there is no
Filament tenant:

- **`QuestionResource` (seller panel)** — `getEloquentQuery()` scopes
  `whereIn('store_uuid', $sellerStoreUuids)`, where `$sellerStoreUuids` comes from
  `OrganizationAuthorizationContract::organizationIdsForUser()` →
  `StoreQueryContract::liveStoresForOrganization()`, the exact pattern Order's seller
  resource uses. A seller sees only questions aimed at their own stores.
- It surfaces **Pending** questions to answer and **Answered** ones as history. An
  **Answer** action opens a required `answer_body` textarea and calls
  `AnswerQuestionAction`.
- **Only the target seller — and the Seller Employee role — may answer**
  (`question.answer`). The employee allow-list gains it deliberately (answering buyer
  questions is delegable staff work, like product authoring); the seller role gets it
  automatically from its guard.

**`AnswerQuestionAction`** (extends `BaseAction`): guards `status->isPending()`,
sets `status=Answered` + `answer_body` + `answered_at` + `answered_by`, and emits
`QuestionAnswered` in `after()`. A hook for a future "cevabınız yayınlandı" notice to
the asker — no listener ships in v1.

---

## 7. Admin moderation — reactive hide (ADR-071)

**`QuestionModerationResource` (admin panel)** — a **separate class** from the seller
resource (the codebase's repeated rule), platform-wide (no tenancy scope), gated on
`question.moderate` (**Admin + Editor**, mirroring Reviews' moderator set). It reads
every question and offers **Hide** (a required reason) and **Un-hide** actions →
`HideQuestionAction` / `UnhideQuestionAction` setting or clearing `hidden_at`. The
admin **does not answer** — hiding is the only lever, because a platform answering
in a seller's place is a promise the seller did not make.

---

## 8. The read & write surfaces

**Public** (`throttle:storefront`, uuid-or-slug via `CatalogBrowseContract`, declared
before the `products/{product}` catch-all):

- `GET /api/v1/products/{idOrSlug}/questions` — the product's **answered, un-hidden**
  Q&A, paginated, newest first. Filter `?seller={storeUuid}` (the target tag, the same
  "bu satıcıya sorulanlar" filter Reviews has). No summary — there is no rating to roll
  up. Each item: masked asker name, question body + date, the answer body + date, the
  seller tag. Store names batched via `StoreQueryContract::publicProfilesFor`.

**Session (asker)** (`['auth:sanctum','throttle:api']`):

- `POST /api/v1/questions` — `{product, body}`; server derives + snapshots the target
  seller (§4); born `Pending`; 201 so the UI says "satıcıya iletildi".
- `GET /api/v1/questions/mine` — the asker's own questions, every status (so a pending
  one is visible), with the answer when present and the product title (resolved in
  batch via `CatalogBrowseContract::productSummaries`).
- `DELETE /api/v1/questions/{question}` — the asker may delete **their own** question
  (hard delete), whether or not it was answered. Ownership is the `QuestionPolicy`.

---

## 9. Storefront (Next.js, desktop session — a separate build step)

- **Product page**: a "Sorular & Cevaplar" section under the reviews — the answered
  Q&A cards (masked asker, question, seller answer, seller tag, dates), a seller
  filter, "daha fazla". For a signed-in customer, a **"Satıcıya Sor"** button →
  a question textarea → `POST /questions` → "Sorunuz satıcıya iletildi, yanıtlanınca
  burada görünecek." A signed-out visitor sees the button prompting sign-in.
- **/hesap**: **"Sorularım"** — the asker's questions with status ("Cevap bekliyor" /
  "Cevaplandı") and the answer when present.

---

## 10. Boundaries and tests

- **`LayeringTest`** gains two `arch()` blocks: Questions imports no other module
  (Catalog, Order, Store, Offer, Inventory, Payment, Shipping, Organization, Reviews,
  Media all forbidden — Core contracts + class-string events only); and no module
  depends on Questions (`toOnlyBeUsedIn` Questions + `App\Providers\Filament` + its
  database/test namespaces). `Questions\Domain` joins the "no cache/request/encrypt in
  Domain" list.
- **Target-authority test**: a stored question's `store_uuid` always equals the
  featured offer's at ask time; a client cannot set it.
- **Visibility test**: a `Pending` question never appears on the public endpoint; an
  `Answered` one does; a hidden one (either state) does not; the asker sees their own
  pending via `/mine`.
- **Seller isolation test**: a seller sees and can answer only questions aimed at
  their own stores; another seller's are unreachable (403/absent). A Seller Employee
  can answer; a Customer cannot reach the seller resource.
- **No purchase gate**: a customer who never bought the product can still ask (the
  positive assertion that distinguishes this module from Reviews).
- **Money**: there is none — no rating, no price. The minor-units rule does not apply.

---

## 11. Deliberately not in v1 (YAGNI)

- **Photos** on a question or answer (text only).
- **A thread** — one question, one answer; no follow-ups or comments.
- **Helpful votes** on a Q&A, and any "most helpful" sort.
- **Reporting** a question by other shoppers (admin finds them via the queue).
- **Admin answering** in the seller's place (admin only hides).
- **Seller decline** — an unwanted question is left pending or hidden by an admin, not
  refused by the seller.
- **Asking a specific non-featured seller** — v1 targets the buy-box winner.
- **Notifications** — `QuestionAsked` / `QuestionAnswered` fire as hooks; no listener
  ships in v1.

---

## 12. ADRs

- **ADR-070** — Questions are product Q&A directed at the buy-box seller
  (server-derived + snapshotted, never client-chosen); any signed-in customer may ask
  (no purchase gate); moderation is reactive — the seller's answer publishes the pair,
  and an admin hides after the fact. The mirror-image of Reviews' purchase gate +
  pre-moderation.
- **ADR-071** — The target seller owns the answer and answers from the seller panel,
  scoped by `store_uuid` tenancy; only that seller (+ the Seller Employee role) may
  answer; the admin's only lever is a reversible hide, never an answer.
