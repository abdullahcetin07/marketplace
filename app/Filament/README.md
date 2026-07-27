# app/Filament

Filament pages that belong to a **panel** rather than to a module.

```
Seller/Auth/    Sign-up, and anything else the /seller panel wires by name.
```

A module's own Filament code does **not** live here — it lives with the module,
in `Modules/{Module}/Presentation/Filament/`. This directory is only for pages
that are part of the panel itself: authentication screens have no owning
business module, and putting them in one would make that module a dependency of
the panel.

---

## Why `Auth/` is not `Pages/`

`SellerPanelProvider` discovers pages from `Filament/Seller/Pages` and registers
every one of them in the navigation. An auth page discovered from there becomes
a menu item shown to already-signed-in sellers.

Auth pages are therefore wired **by name** instead — `->registration(...)`,
`->login(...)` — from a namespace the discovery path cannot reach. Moving one
into `Pages/` is not a refactor; it changes what the navigation shows.

---

## Why the panels do not use Filament's stock auth pages unmodified

`users` has no `name` column: it was split into `first_name` + `last_name`
(ADR-012), and the computed `display_name` is deliberately not mass assignable.
Filament's stock Register page posts a single `name` field, so it cannot create
a user here at all.

The fix belongs in this layer. Adding `name` to `$fillable` to satisfy a form
would put a second source of truth back into the model that ADR-012 removed.

@see `Seller/Auth/Register.php`, `app/Providers/Filament/SellerPanelProvider.php`
