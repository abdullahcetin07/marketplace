# Questions ("Satıcıya Sor")

A signed-in shopper's public question about a **product**, aimed at the
**buy-box seller** the server picked and snapshotted, which **that seller
answers** from the seller panel. The answered pair is public.

Full specification: [docs/modules/Questions.md](../../../docs/modules/Questions.md).
Decisions: ADR-070 (product Q&A at the buy-box seller, server-derived and
snapshotted; no purchase gate; reactive moderation), ADR-071 (the target seller
owns the answer; the admin's only lever is a reversible hide).

## What bites

- **It is the mirror-image of Reviews, not a copy of it.** A review reports an
  experience, so it is gated on a delivered purchase and pre-moderated. A
  question is asked to decide *whether to buy*, so there is **no purchase
  gate** — and the **seller's answer** is what publishes it, not a moderator.
- **The target is server-derived and frozen.** `POST /questions` carries
  `{product, body}` and no seller. The action reads the featured offer and
  snapshots its store, so nobody can aim a question at a shop that is not
  selling the thing, and a later buy-box change never re-aims a past question.
- **No sellable offer means no ask** (422). A product nobody is selling has no
  seller to ask — an ordinary state, so a clean refusal rather than an error.
- **Hiding is a flag, not a status.** An admin may hide a *pending* question (an
  abusive one, before any seller sees it) or an answered one, and may un-hide.
  Public = `Answered` **and** `hidden_at IS NULL`, computed on read.
- **An admin never answers** (ADR-071). The platform speaking in a merchant's
  place is a promise the merchant did not make.
- **A Seller Employee may answer.** Answering buyer questions is delegable staff
  work, like product authoring.
- **It imports no module.** Catalog, Offer, Store and Organization all through
  Core contracts. No photos, no media trait, and no money at all.
