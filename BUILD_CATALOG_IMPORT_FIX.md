# BUILD — Catalog Import Fix (ADR-075, amends ADR-047)

**Status:** Ready. Decision: ADR-075 (in `docs/Architecture_Decision_Record.md`),
amendment log entry #14 in `docs/001_Architecture.md`.

Two independent fixes, both required. **Fix A** is the correctness decision
(ADR-075). **Fix B** is the retry-storm cap and is a separate concern that must
ship in the same change. Do not re-run the failed import until both are in.

Everything runs in Docker. `make check` must pass before this is done.

---

## Context — what happened on the first real import

The first live import failed **5 rows**, all one cause: `categoryRejectsProducts`.
The catalogue legitimately sells a product **directly at a node that is also a
parent**:

```
Row A:  Cilt Bakımı > Cilt Temizleme Ürünleri > Cilt Temizleyiciler   (product at the leaf)
Row B:  Cilt Bakımı > Cilt Temizleme Ürünleri                         (product AT the middle node)
```

Row A creates `Cilt Temizleme Ürünleri` as an **intermediate** node, which
`CatalogTaxonomyResolver::categoryByPath()` opens with `accepts_products = false`
(the "shelves, not shelves' contents" default at line 87). Row B then terminates
at that same node and is refused at **line 91–93** — by the flag the import itself
set seconds earlier. ADR-047 already allows `accepts_products = true` **with**
children; the import just fought its own default.

Separately, that rejection escaped to the **chunk-job level**: `ImportCsv` has no
`$tries` ceiling, no `$backoff`, and a 24-hour `retryUntil`, so those 5 rows drove
**29,074 attempts** and ~155,000 duplicate `failed_import_rows` overnight.

---

## Fix A — the import opens a category IT created; a human-closed one still refuses

**The rule (ADR-075):** a category the import created carries no human moderation
decision, so when a row terminates at it (a product is sold directly there), the
import **opens it** (`accepts_products = true`). A category a **human** left closed
in the Category Manager is a real judgement — the row is still refused and reported,
exactly as ADR-047 says. The discriminator is a persisted origin marker, cleared the
moment a human edits the category.

### A1. Migration — origin marker on `categories`

Add a boolean:

```
categories.created_by_import  BOOLEAN NOT NULL DEFAULT false
```

Existing rows default to `false` — i.e. treated as human-owned, so any category that
exists today keeps ADR-047's conservative behaviour (a closed one still rejects).
Follow `docs/003_Database_Standards.md`.

### A2. `CreateCategoryDTO` + `CreateCategoryAction`

- Add `public readonly bool $createdByImport = false` to `CreateCategoryDTO`
  (default `false`, so nothing else that constructs it changes).
- `CreateCategoryAction` persists it to the new column.

### A3. `CatalogTaxonomyResolver::categoryByPath()` — pass the marker + open-not-throw

Two edits in the loop (lines ~80–95):

1. Every category the resolver creates is import-created — pass
   `createdByImport: true` on the `CreateCategoryDTO`.

2. Replace the unconditional throw at line 91–93 with the ADR-075 branch:

```php
if ($isLeaf && ! $found->acceptsProducts()) {
    if ($found->created_by_import) {
        // ADR-075: the import made this node and no human has touched it, so its
        // `false` is a default, not a decision. A row sells here → open it.
        $this->openForProducts($found);   // sets accepts_products = true, persists,
                                          // and reflects it on $found in memory
    } else {
        // A human left this closed in the Category Manager — ADR-047 stands.
        throw CatalogImportException::categoryRejectsProducts($path, $name);
    }
}
```

`openForProducts()` may reuse `UpdateCategoryAction` if that action can flip
`accepts_products` (the resolver has 3 constructor deps; a 4th is under the ceiling of
7), or a small dedicated persist — your call. Make sure `$found` reflects
`accepts_products = true` afterward so the rest of the walk sees it.

Update the docblock at lines 57–60 — it currently states the old "existing category
that does not accept products is a failed row" rule verbatim; it must now read: an
**import-created** closed node is opened, a **human-closed** one is a failed row
(cite ADR-075).

### A4. Any human edit transfers ownership

When a human edits a category through the Category Manager (`UpdateCategoryAction`
and/or the Filament `EditCategory` save), set `created_by_import = false`. This makes
the marker mean precisely *"import made it and no human has touched it since"* — so a
category an admin later curates (including one they deliberately keep closed) is never
reopened by a re-import. Without this, a re-import could reopen a category a human
meant to keep closed.

---

## Fix B — a rejected row must not storm the job (independent, ship together)

### B1. Root: reject at the ROW level, never throw out of the chunk

A `CatalogImportException` raised while resolving a row (a human-closed category, a
missing lookup, etc.) must be recorded in `failed_import_rows` and let the chunk
**continue** — it must not bubble to the `ImportCsv` job, which is what got retried
29,074 times.

In `ProductImporter` (`app/Modules/Catalog/Presentation/Filament/Imports/`), catch the
domain failure where the row is processed (`resolveRecord()` / wherever
`CatalogRowImporter` runs) and re-throw Filament's per-row failure exception so the
framework records it and moves on:

```php
try {
    // ... resolve the row, drive the authoring actions ...
} catch (CatalogImportException $e) {
    throw new \Filament\Actions\Imports\Exceptions\RowImportFailedException($e->getMessage());
}
```

Verify against your Filament 3.3 API that `RowImportFailedException` (or the version's
equivalent) is the exception `ImportCsv` catches per-row and records — that is the one
to throw. The message must carry the human-readable cause (the current
`categoryRejectsProducts` text) so the failure report stays useful.

### B2. Safety net: cap retries so no future defect can storm

Independently of B1, no import defect should ever again turn a bad row into tens of
thousands of retries. Add an explicit retry ceiling and a backoff. Pick the lever your
Filament version exposes:

- Shorten the job retry window — override the importer's `getJobRetryUntil()` from
  `now()->addDay()` to something like `now()->addMinutes(10)`; **and**
- Add an explicit `$tries` ceiling (e.g. 3) and a `$backoff` (e.g. `[30, 120, 300]`
  seconds) — via a custom `ImportCsv` job subclass wired to the `ImportAction`, or the
  importer's job-config hooks, whichever 3.3 supports.

The outcome that matters: a failing chunk job retries a **small, bounded** number of
times with a growing delay, then stops — never 29,074 instant attempts.

---

## Tests (Feature — importer touches the DB)

1. **ADR-075 open-not-throw:** import a two-row fixture where one row nests under a node
   and another sells directly at that node (the `Cilt Temizleme Ürünleri` shape).
   Assert **both rows succeed**, the node ends `accepts_products = true` and
   `created_by_import = true`, and the child exists.
2. **ADR-047 preserved:** pre-create a category with `accepts_products = false` and
   `created_by_import = false` (a human-closed node). Import a row terminating there.
   Assert the row is **recorded as failed** (not thrown), with the category-rejects
   message, and the category stays closed.
3. **Ownership transfer (A4):** import creates a node (`created_by_import = true`); a
   human edit clears the flag; a subsequent import row selling at it is now **refused**
   like any human-closed node.
4. **Order independence:** the same two paths in reverse row order both succeed.
5. **Idempotency:** re-running the same file changes nothing (no duplicate categories,
   flags stable).
6. **Retry cap (B):** a row that raises `CatalogImportException` lands in
   `failed_import_rows` and the sibling rows in the chunk still import — the job is not
   retried into a storm. (A unit-level assertion that the domain exception is translated
   to the Filament row-failure exception is enough if a full job-retry test is awkward.)

---

## After it lands

1. `make check` green.
2. Deploy: `git pull`, `php artisan migrate` (the new column), `optimize:clear`.
3. Ensure a queue worker / Horizon is running (`media` + the import queue).
4. Re-upload the same catalogue file — idempotent on GTIN, so the 11 previously-good
   products stay and the 5 previously-rejected rows now import. Confirm the failure
   report is empty (or only genuinely-bad rows remain).
5. Report: rows imported, rows failed (and why, if any), and that the retry count
   stayed bounded.
