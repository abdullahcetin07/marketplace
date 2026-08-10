# BUILD — Seller offer surface shows the LIVE sellable stock (not the stale declared number)

**Owner-approved.** Small, single-resource change.

**The problem:** the seller's "Teklifler" (Offer) list shows the offer's declared
`stock_quantity` (a static number the seller typed, e.g. 5). It does NOT decrement as
units sell — only Inventory's `on_hand` does. So an offer that sold all 5 units shows
"stok 5" while it is actually **sold out** (`available = 0`) and correctly hidden from
the buy box — and the seller has no idea. The Inventory "Stok durumu" page already
shows the truth (`Satılabilir: 0`); the Offer list does not.

**The fix:** the seller Offer list shows the **live available** from Inventory, with a
sold-out badge at 0. The declared `stock_quantity` stays the editable field the seller
sets (the mirror still reads it) — only the *display* becomes honest.

---

## The change

**File:** `app/Modules/Offer/Presentation/Filament/Seller/Resources/OfferResource.php`

**Today** (table, ~lines 188–194): a `TextColumn::make('stock_quantity')->badge()
->color(fn (Offer $record) => $record->isInStock() ? 'gray' : 'warning')`.

**Do this:**
1. **Add a live "Satılabilir" column** reading the Core contract per row (the exact
   pattern the `CatalogLabels` column on this same resource already uses, and the
   `available` column on `Inventory/.../StockResource.php` lines 186–194):
```php
Tables\Columns\TextColumn::make('available')
    ->label(__('offer.field.available'))          // "Satılabilir"
    ->badge()
    ->getStateUsing(fn (Offer $record): int =>
        app(\App\Core\Domain\Contracts\InventoryQueryContract::class)
            ->availableFor($record->variant_uuid, $record->selling_org_uuid))
    ->formatStateUsing(fn (int $state): string =>
        $state === 0 ? __('offer.stock.sold_out') : (string) $state)   // 0 → "Tükendi"
    ->color(fn (int $state): string => match (true) {
        $state === 0 => 'danger',
        $state <= 5  => 'warning',
        default      => 'success',
    }),
```
   `availableFor(string $variantUuid, string $sellingOrgUuid): int` = `on_hand −
   reserved`, floored at 0 (`InventoryQueryContract`). The Offer row carries both
   `variant_uuid` and `selling_org_uuid` (uuid). It is a per-row scalar call, no join —
   Inventory is a different bounded context, exactly as `OfferQuery::eligible()` reads
   it. Do **not** add it to `getEloquentQuery()`'s eager loads.

2. **Relabel the existing `stock_quantity` column** to make the distinction explicit —
   e.g. label it "Beyan edilen" (declared) and drop its warning color (the live column
   now carries the signal), OR remove the `stock_quantity` column from the TABLE
   entirely and keep it only in the edit FORM. Recommended: keep a muted "Beyan edilen"
   column so the seller sees both "I said 5 / 0 sellable → restock".

3. **In the edit form** (`stock_quantity` TextInput, ~lines 157–163): add a
   `->helperText()` showing the current available, e.g. "Şu an satılabilir: {n}. Yeni
   stok girince bu sayı güncellenir." — resolve `availableFor(...)` in a closure. This
   tells the seller what they actually have before they overwrite it. (The mirror is
   absolute: setting `stock_quantity = N` sets Inventory `on_hand = N`, ADR-048.)

**Lang keys:** add `offer.field.available` ("Satılabilir"), `offer.field.declared`
("Beyan edilen"), `offer.stock.sold_out` ("Tükendi") to the offer lang files.

---

## Test

`tests/Modules/Offer/Feature/` (or the existing seller-resource test): an offer whose
Inventory `on_hand` is 0 (all committed/sold) renders the "Satılabilir" column as
`Tükendi`/danger even though its `stock_quantity` is 5; an offer with `available > 0`
renders the number with success/warning color.

## Steps

Edit the resource → lang keys → test → `make check` green → commit + push
`feat(offer): seller sees live sellable stock, not the stale declared number`.

## Not a schema change

`stock_quantity` stays as the seller's declared input and the mirror's source. This is
display-only. A future ADR could decrement the declared number on sale (keep the two in
lockstep), but that is a bigger change and out of scope here.
