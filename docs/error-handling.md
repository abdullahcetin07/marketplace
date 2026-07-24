# Error handling

---

## Domain failures are not bugs

A marketplace fails in expected ways constantly: a store is not approved, an
offer is out of stock, a seller tries to edit another seller's listing. Those
are business outcomes, not defects. They must not surface as 500s and must not
reach the error tracker.

`App\Core\Domain\Exceptions\BaseException` makes a failure self-describing:

```php
final class StoreNotApproved extends BaseException
{
    protected int $status = 403;

    public static function for(Store $store): self
    {
        return (new self)->withContext(['store_id' => $store->id]);
    }
}
```

It carries:

| | |
|---|---|
| `$status` | HTTP status when it escapes to the HTTP layer |
| `getErrorCode()` | `store_not_approved` — derived from the class name; what the frontend branches on |
| `userMessage()` | Translated, from `lang/{locale}/errors.php`, keyed by the error code |
| `$context` | Structured data for logs — never rendered raw to users in production |
| `$reportable` | **`false` by default** |

**Genuine bugs should keep throwing native exceptions** so they stay loud.
Extending `BaseException` is a statement that a failure is expected.

---

## The response envelope

One shape everywhere, so the Next.js frontend never special-cases an endpoint.

Success (`BaseController`):

```json
{ "data": { ... }, "meta": { "page": 1, "per_page": 25, "total": 240 } }
```

Failure (`BaseException::render()`):

```json
{ "error": { "code": "store_not_approved", "message": "...", "context": { ... } } }
```

Validation failures use the **same** shape, not Laravel's default —
`BaseRequest::failedValidation()` renders `code: "validation_failed"` with the
field errors under `context.fields`.

Authentication failures likewise, via the handler in `bootstrap/app.php`.

---

## What reaches the error tracker

`$reportable` defaults to `false`. Expected domain failures are not incidents
and must not bury the real ones.

Set it `true` only when the failure represents something nobody else will
notice. `SearchIndexingFailed` is the canonical example: a document that fails
to index is silent data loss from the customer's point of view — the product
exists but cannot be found — and no other signal exists.

Everything that is *not* a `BaseException` is written to the `errors` channel
with the correlation id (`bootstrap/app.php`), then reported normally.

---

## Validation

Validation happens in the `FormRequest` and nowhere else. A DTO is a shape, not
a guarantee; services assume they are given already-valid data.

Two `BaseRequest` behaviours worth knowing:

**`authorize()` defaults to `false`.** Laravel's default is `true`, which means
a forgotten override silently opens an endpoint. Inverting it turns the same
mistake into a visible 403 during development.

**Empty strings become null** before validation (`prepareForValidation`).
Without this, a cleared optional field arrives as `""` and a `nullable` rule
passes it straight through — into a NOT NULL column, or worse, storing an empty
string where the domain means "absent".

---

## Adding an exception

1. Extend `BaseException` in the module's `Domain/Exceptions/`.
2. Set `$status`.
3. Add the translated message to `lang/tr/errors.php` **and**
   `lang/en/errors.php`, keyed by the snake_case class name.
4. Set `$reportable = true` only if nothing else would surface the problem.
5. Use a named static constructor (`::for()`, `::from()`) so call sites read as
   domain language.
