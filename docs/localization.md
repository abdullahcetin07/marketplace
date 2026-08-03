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
| `geo_provinces` | il — name + TR plate code, per country | `(country, name)` |
| `geo_districts` | ilçe | `(province, name)` |
| `geo_neighborhoods` | mahalle | `(district, name)` |

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

GET /api/v1/geo/provinces?country=TR                      il
GET /api/v1/geo/districts?province=İstanbul               ilçe
GET /api/v1/geo/neighborhoods?district=Kadıköy&province=İstanbul   mahalle
```

Unauthenticated by design — the storefront needs the switcher before anyone
signs in, and everything served is already public on the rendered page. The geo
cascade is anonymous for the same reason plus one more: a guest fills in a
delivery address before they have an account.

**A parent may be named or identified.** `?province=İstanbul` and
`?province={uuid}` both work, and the NAME is the case that matters — ADR-056
stores `city`/`district` as free strings, so a client reopening a *saved* address
holds names and no ids. Matching is diacritic- and case-insensitive
("Kahta"/"Kâhta", "istanbul"/"İSTANBUL"), because two lists in this repo already
spell four district names differently.

**An unresolvable or missing parent is an empty list, not a 404.** A saved
address may name a district that has since been renamed; "no options" lets the
form fall back to free text, while a 404 reads as a broken endpoint. There is
deliberately **no "all neighbourhoods" route** — 73,300 rows is not a payload,
and a route that can express it is one somebody eventually calls on page load.

**Sorted with a `tr` collator in PHP, not by SQL.** Turkish order interleaves ç
after c, ğ after g, ı before i, ö after o, ş after s, ü after u — which neither
SQLite's byte comparison nor a default-collation Postgres produces, so
`ORDER BY name` puts İstanbul after Isparta. Every read here is one parent's
children, so sorting them in PHP costs nothing and is correct on every driver.

---

## Seeding

`LocalizationSeeder` is **installation, not fixtures**. Without a default
language and currency, `Language::default()` and `Currency::default()` throw and
every request fails.

Idempotent, and deliberately does **not** overwrite `exchange_rate` on re-run —
a deploy must not reset rates the update job has since refreshed.

In tests: `$this->seedPlatform()`.

### `TurkeyGeoSeeder` — il / ilçe / mahalle

81 provinces, 973 districts, **73,300 neighbourhoods**. **Not** registered in
`DatabaseSeeder` and not part of `seedPlatform()`: an operator runs it once, and
73k rows in every test database would make the suite unusable. A test that needs
geography builds the three rows it is testing.

```bash
php artisan db:seed --class="Database\Modules\Localization\Seeders\TurkeyGeoSeeder"
```

The data is committed **gzipped** at `database/Modules/Localization/data/tr-geo.json.gz`
(400 KB; 1.5 MB of JSON). Fetching it at seed time would make a deploy depend on a
third-party host still existing and still serving the same shape — the failure
nobody can diagnose at 2am. Source: `muratgozel/turkey-neighbourhoods` (MIT), from
the NVİ registry.

Names were normalised on the way in: the redundant "Mah" label dropped
("Caferağa Mah" → "Caferağa"), whitespace collapsed, and the first letter of the
disambiguating parenthetical capitalised ("(konalga Köyü)" → "(Konalga Köyü)").
"Köyü", "Beldesi" and "Yaylası" are **kept** — they are the place TYPE, not a
label, and a village and a neighbourhood of the same name are different places.

Idempotent on `(parent, name)`, which the UNIQUE indexes enforce, and it **never
touches `is_active`** — a neighbourhood an operator deactivated stays deactivated
through a re-seed. That is the property that matters: re-seeding must not undo an
operator's decision.
