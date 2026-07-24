# app

```
Core/        Foundation — base classes, layered. No business logic.
Shared/      Enums, traits, rules, helpers used everywhere.
Modules/     Business modules. Empty until Sprint 1.
Models/      User + the three actor subclasses (Admin, Seller, Customer).
Providers/   Service providers, including both Filament panels.
Http/        Global middleware.
Console/     Artisan commands.
```

Each directory has its own README. Start with [Core](Core/README.md).

---

## Where does my code go?

| It is... | It goes in |
|---|---|
| A business rule about products/orders/stores | `Modules/{Module}/` |
| A base class every module will extend | `Core/{Layer}/` |
| An enum, trait or helper two modules will share | `Shared/` |
| Authentication identity | `Models/` |
| Global bootstrapping | `Providers/` |

If it does not fit any of these, it probably belongs in a module you have not
created yet.

---

## Models

One `users` table; three subclasses scoped by `users.type`. That is what makes
the three guards genuinely independent — a seller session cannot resolve as an
admin because the admin provider's query cannot see the row.

Full reasoning, including what it costs:
[docs/authentication.md](../docs/authentication.md).

`Models/` holds identity only. Business models live in their modules.

---

## Providers

| Provider | Responsibility |
|---|---|
| `AppServiceProvider` | Strict mode, query budgets, rate limiters, password defaults, HTTPS |
| `AuthServiceProvider` | Super-admin gate, denial auditing |
| `EventServiceProvider` | Domain-event audit subscriber, authentication auditing |
| `SearchServiceProvider` | OpenSearch client + Scout `opensearch` driver |
| `HorizonServiceProvider` | Dashboard permission gate, failure alerts |
| `Filament/AdminPanelProvider` | `/admin`, `admin` guard, registration disabled |
| `Filament/SellerPanelProvider` | `/seller`, `seller` guard, registration enabled |

`AppServiceProvider` is where the settings live that are impossible to retrofit
— read it before changing anything about model behaviour.
See [docs/performance.md](../docs/performance.md).
