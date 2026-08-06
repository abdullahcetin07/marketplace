# Reviews

One buyer's **rating (1–5) + optional text + optional photos** of a product they
were **delivered**, published **only after a moderator approves it**. The
catalogue is shared, so a review is about the **product** and carries the
**seller it was bought from** as a tag copied from the order line — never chosen
by the buyer.

Full specification: [docs/modules/Reviews.md](../../../docs/modules/Reviews.md).
Decisions: ADR-066 (product-attributed, seller-tagged), ADR-067 (bound to one
delivered order line; the gate is delivery, not payment), ADR-068
(pre-moderation by Admin/Editor; photos moderated with the review), ADR-069
(dedicated public endpoints; the summary is computed on read).

## What bites

- **A review is bound to an ORDER LINE, not to (customer, product).**
  `order_line_uuid` is UNIQUE, so buying the same product twice earns two
  reviews — each is a distinct purchase experience — while a second review of
  the *same* purchase is refused by the database.
- **The gate is DELIVERY, not payment.** "Kullandım, şöyleymiş" is what a review
  promises; a paid-but-unshipped order has no experience to report.
- **The seller tag is copied from the line, never sent by the client.** It is
  re-verified in the action, not only in the request — the eligibility read a
  storefront does is a convenience, never the authority.
- **Nothing is visible until a moderator publishes it**, and the seller has no
  lever: not approve, not hide, not reject.
- **The average is computed on read**, never stored — the same discipline as the
  buy box (ADR-045). A deleted review changes it on the next read with nothing
  to invalidate.
- **It imports no module.** Catalog and Order through Core contracts, photos
  through the shared `HasMedia` trait (not the Media module), store names
  through `StoreQueryContract`.
