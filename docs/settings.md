# Settings

Business configuration, editable without a deploy.

**Settings are not config.** Reasoning: [001_Architecture.md §16](001_Architecture.md).

| | Holds | Read before boot | Changing it |
|---|---|---|---|
| `config/*.php` | what the **application** needs | yes | deploy |
| `settings` table | what the **business** decides | no | click |

A value required before the framework boots can never be a setting — reading
one needs the database connection that config already defined.

---

## Reading

```php
settings('company.name')                        // global helper
settings('checkout.guest_enabled', false)       // with a default
settings()->boolean('sms.enabled')              // typed accessor
settings()->integer('media.max_upload_mb', 10)
settings()->array('media.responsive_widths')
```

Typed accessors coerce safely — a mistyped read returns the default rather than
a half-cast value.

**Failure mode, deliberate:** if the table is missing or the database is
unreachable, `get()` returns the caller's default rather than throwing.
Settings decorate behaviour; they must not be able to take the platform down,
and this keeps `artisan migrate` working on an empty database.

---

## Caching

**The whole table under one key.** Settings are read many times per request
(a layout, an email, a policy) and written a handful of times per month.
Caching per key would mean dozens of cache round-trips per request and a
partial-invalidation problem; the whole table is one round-trip and a few
kilobytes.

Any write flushes it. Plus a per-request memo, because a layout may read the
same key a dozen times.

---

## Types

One text column plus a `type` column — not a column per type, which would mean
a migration for every new kind of setting.

The cost is that `false`, `0` and `"0"` are indistinguishable coming out, which
is **exactly** why the type is stored and applied on read. Without it, a boolean
setting set to false returns `"0"`, which is truthy in some comparisons and
falsy in others.

| Type | Stored as | Returns |
|---|---|---|
| `string` / `text` | raw | `string` |
| `integer` | decimal string | `int` |
| `boolean` | `'1'` / `'0'` | `bool` |
| `json` | JSON | `array` |

`value` null means "never set, use `default_value`". That is distinct from an
empty string, which is a deliberate blank — collapsing them would make "reset to
default" impossible.

---

## Groups

General, Company, Email, SMS, Media, SEO, Localization, Security, Performance,
System.

**Restricted** — Security, Performance, System — additionally require
`setting.manage_restricted`. An Editor holding `setting.update` still cannot
change session lifetimes or take the platform into maintenance mode.

**Publicly readable** — General, Company, SEO, Localization. A setting is served
to an unauthenticated client only if **both** its group is publicly readable
**and** its own `is_public` flag is set **and** it is not encrypted. Three
independent gates, because one forgotten flag on an SMTP password would publish
a credential.

---

## Flags

| Flag | Meaning |
|---|---|
| `is_public` | may be exposed to unauthenticated clients (with the gates above) |
| `is_encrypted` | encrypted at rest; a business-owned third-party credential |
| `is_locked` | read by code, by key — displayable, never editable or deletable |

Encrypted settings hold credentials that belong to the **business** (SMTP
password, SMS API key), as opposed to the **deployment** (database password),
which belongs in `.env`.

Encryption lives in **Infrastructure** (ADR-019):
`Settings\Infrastructure\Casts\EncryptedSettingValue`, applied to the `value`
column. It is conditional on the row's own `is_encrypted` flag, which is why it
reads `$attributes` rather than being applied unconditionally — only flagged
rows are ciphertext, and casting the rest would corrupt them.

`Setting::typedValue()` therefore sees plaintext and never calls `decrypt()`.

The rotated-`APP_KEY` behaviour is preserved: the cast returns null on a
`DecryptException`, so `typedValue()` falls through to `default_value` rather
than leaking ciphertext into live configuration.

`Setting` (Domain) names the cast (Infrastructure) in `casts()`. **Permitted by
ADR-023** — Eloquent is Active Record, so declaring ORM metadata is not a
dependency. Naming a class is metadata; calling a method on an Infrastructure
service would not be.

An encrypted value written under a since-rotated `APP_KEY` degrades to the
default rather than returning ciphertext into live config.

Locked settings are seeded infrastructure. Renaming or deleting one turns a
working feature into a runtime null dereference — `SettingPolicy` refuses
create and delete outright for every setting.

---

## Registering

Modules register their own settings at boot; nobody hand-edits a seeder.

```php
app(SettingsService::class)->register(
    key: 'checkout.guest_enabled',
    group: SettingGroup::General,
    type: SettingType::Boolean,
    default: true,
    label: 'Allow guest checkout',
    isPublic: true,
);
```

`register()` only ever fills **metadata**. It never overwrites a value an
operator has set — which is what makes `SettingsSeeder` safe on every deploy.

---

## Writing

```php
settings()->set('company.name', 'Acme');
$refused = settings()->setMany([...]);   // keys that were locked or unknown
```

`set()` returns `false` for a locked or unknown key rather than throwing, so a
bulk settings-form update reports which fields were refused without aborting
the rest.

Every write dispatches `SettingUpdated`. Encrypted settings dispatch
`'[redacted]'` for both values — an SMTP password must not travel through the
audit log and the queue in plaintext just because it changed.

`SettingUpdated::requiresReconfiguration()` tells a listener whether the change
needs a service rebuilt (mail transport, SMS provider) or merely takes effect on
the next read.

---

## Auditing

`Setting` carries the `Auditable` trait — settings changes are exactly what a
dispute turns on ("who enabled guest checkout, and when?").

This is the one permitted cross-module dependency from Settings, documented in
`tests/Architecture/LayeringTest.php`. The alternative — Audit reaching into
Settings — is the worse direction.
