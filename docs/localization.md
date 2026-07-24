# Localization

Turkish primary, English fallback, unlimited additional languages.

Country, Currency and Language are **lookup tables**, not enums — amended in
Sprint 1. Reasoning and cost: [001_Architecture.md §9](001_Architecture.md).

---

## Tables

| Table | Holds | Key |
|---|---|---|
| `languages` | locale, direction, native name, default flag | `code` (`tr`) |
| `countries` | ISO codes, phone code, EU membership, flag | `iso2` (`TR`) |
| `currencies` | symbol, separators, decimals, exchange rate | `code` (`TRY`) |
| `timezones` | IANA name, curated display label | `name` |
| `translations` | admin-editable string overrides | `(language, group, key)` |

Exactly one `languages` row and one `currencies` row may be `is_default` —
enforced by a **partial unique index**, not only by a model hook. Two defaults
would make `Currency::default()` return whichever row the planner picked, which
manifests as prices changing between requests.

---

## Language resolution

`SetLocale` middleware, highest precedence first:

1. `?lang=` query parameter
2. `X-Language` header (what the Next.js storefront sends)
3. the authenticated user's saved preference
4. `Accept-Language` negotiation
5. platform default

**Why this order.** An explicit choice must beat a stored preference, or a user
following a Turkish share link while their account is English gets a page that
contradicts the link they clicked. A stored preference must beat
`Accept-Language`, or a Turkish speaker on an English laptop can never make the
choice stick.

Only **active** languages are honoured at every step — a disabled locale is not
reachable by guessing its code.

Responses carry `Content-Language` and `Vary: Accept-Language, X-Language`.
Without the `Vary`, a shared cache serves a Turkish page to an English speaker.

---

## Translations

`lang/{locale}/*.php` files are the **source of truth for which keys exist**.
The `translations` table **overlays** them.

```
database override → lang/{locale}/{group}.php → the key itself
```

**Why files stay authoritative.** A developer adding `errors.new_thing` gets a
working key with no database row, and an operator who makes a mistake can delete
their override to fall back to the shipped copy. A pure database loader means
every new key needs a seeder, and a missing row renders a raw dotted key to a
customer.

`DatabaseTranslationLoader` extends Laravel's `FileLoader` and is bound in
`LocalizationServiceProvider::register()` — it must be in place before the
translator singleton resolves.

**Failure mode, deliberate:** if the database is unreachable it falls back
silently to file translations. A degraded locale beats a site-wide 500.

Vendor package translations (`namespace` set) are **not** overridable — those
keys change on every package upgrade and an operator cannot see their context.

---

## Money

Integers of minor units. Always. `decimal_places` is the exponent, not
permission to use floats.

```php
$minor = $currency->toMinor('1499.90');   // 149990
$currency->format($minor);                 // "1.499,90 ₺"
$try->convertTo($usd, $minor);             // via the base currency
```

Formatting uses the **currency's own** separators and symbol position, not
`NumberFormatter` and not the viewer's locale. A Turkish admin viewing USD
should see USD written the way USD is written.

`exchange_rate` is `decimal(20,10)` — a rate multiplied against a large order
total loses real money to binary rounding.

**Check `hasFreshRate()` before pricing anything.** A stale rate is worse than a
missing one: it silently misprices. The base currency is always fresh (its rate
is 1.0 by definition).

---

## Timezones

`offset_minutes` is a **cached convenience for sorting and display only**. It is
wrong for half the year in any DST zone. Never compute a time from it — use
`toDateTimeZone()` or `currentOffsetMinutes()`.

Timestamps are stored UTC (`timestamptz`) and rendered in the user's zone.

---

## RTL

`languages.direction` is `ltr` or `rtl`, cast to `TextDirection`. Structural
from day one so adding Arabic does not mean touching every layout:

```php
$language->isRtl();
$language->direction->start();   // 'right' for RTL
```

---

## Caching

Caching lives in **Infrastructure repositories** (ADR-019) — never in the Domain
models. Four ports, declared in `Domain/Contracts/` and implemented in
`Infrastructure/Repositories/`:

```php
app(LanguageRepositoryContract::class)->default();
app(CurrencyRepositoryContract::class)->findByCode('TRY');
app(CountryRepositoryContract::class)->active();
app(TimezoneRepositoryContract::class)->default();
```

Services and Presentation type-hint the **contract**, never the concrete
repository, so both stay testable against a fake.

Every lookup is cached for an hour and flushed by `LocalizationCacheObserver`
on write:

`localization:language:default`, `localization:currency:default`,
`localization:languages:enabled`, `localization:country:iso2:{ISO}`,
`localization:translations:{languageId}:{group}`

`LocalizationService::bootstrapPayload()` is one cached call serving the whole
language switcher and currency list — four round-trips on first paint is the
difference between a fast site and a slow one.

---

## API

```
GET /api/v1/localization              languages + currencies + current locale
GET /api/v1/localization/countries    separate: large, only checkout needs it
GET /api/v1/localization/timezones
```

Unauthenticated by design — the storefront needs the switcher before anyone
signs in, and everything served is already public on the rendered page.

---

## Seeding

`LocalizationSeeder` is **installation, not fixtures**. Without a default
language and currency, `Language::default()` and `Currency::default()` throw and
every request fails.

Idempotent, and deliberately does **not** overwrite `exchange_rate` on re-run —
a deploy must not reset rates the update job has since refreshed.

In tests: `$this->seedPlatform()`.
