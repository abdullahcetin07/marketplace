# Core/Presentation

**How it is exposed.** The outermost layer. Nothing depends on it.

| Directory | Holds |
|---|---|
| `Controllers/` | `BaseController` — the response envelope |
| `Policies/` | `BasePolicy` — authorisation |
| `Requests/` | `BaseRequest` — the trust boundary |
| `Resources/` | `BaseResource` — API output |

---

## Controllers are thin by contract

Resolve a DTO from the request → authorise → call one service or action →
return a resource. No queries, no business rules.

One envelope everywhere, so the Next.js frontend never special-cases an
endpoint:

```json
{ "data": ..., "meta": { "page": 1, "per_page": 25, "total": 240 } }
{ "error": { "code": "...", "message": "...", "context": ... } }
```

---

## Two inverted defaults

**`BaseRequest::authorize()` returns `false`.** Laravel's default is `true`,
which means a forgotten override silently opens an endpoint. Inverting it turns
the same mistake into a visible 403 during development.

**`BasePolicy::owns()` returns `false`.** Correct for admin-only resources. But
it means a seller-facing policy that forgets to implement ownership **denies
everything** — a loud failure rather than a silent grant.

---

## Policies check permissions, never roles

`$user->hasPermissionTo('store.update', $user->guardName())` — never
`$user->hasRole('Category Manager')`.

Roles are bundles that an operations team reshuffles at runtime; hard-coding one
into a policy makes that bundle un-reshuffleable. Role **ids** appear nowhere at
all — they differ per environment and mean nothing.

See [docs/authorization.md](../../../docs/authorization.md).

---

## Resources never expose internal ids

`BaseResource::publicId()` returns the UUID. Sequential ids leak business
volume — register, place one order, read off the platform's order count.

`whenPermitted()` includes a field only when the actor holds a permission, for
data that is legitimate for staff but not for the public.
