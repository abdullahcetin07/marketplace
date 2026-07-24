# MarketplaceOS Database Standards
Version: 1.1

Synchronized with the Architecture Decision Record (ADR-004, 005, 006, 014,
015, 016).

---

# 1. Purpose

This document defines every database standard used in MarketplaceOS.

Every migration, model and relationship MUST follow these standards.

If any **sprint** conflicts with this document, this document takes precedence.

This document sits at position 4 in the precedence chain (ADR-003). It is
outranked by `CLAUDE.md`, the ADR and `001_Architecture.md`. See §30.

---

# 2. Database Engine

Database Engine

PostgreSQL 17+

Character Set

UTF-8

Collation

Default PostgreSQL

Timezone

UTC

Application Timezone

Europe/Istanbul

---

# 3. Primary Keys

Every table uses:

BIGINT
AUTO INCREMENT

Example

id BIGINT PRIMARY KEY

Never use UUID as primary key.

---

# 4. Public Identifier

Every public entity contains

uuid UUID UNIQUE

UUID is used for

Public URLs

REST APIs

Imports

Exports

Integrations

Webhooks

Never expose internal numeric IDs.

---

# 5. Foreign Keys

Every foreign key references BIGINT.

Correct

organization_id

product_id

offer_id

store_id

Wrong

organization_uuid

product_uuid

Never create UUID foreign keys.

---

# 6. Standard Columns

Every business table contains

id

uuid

created_at

updated_at

deleted_at (Soft Delete where applicable)

created_by

updated_by

deleted_by (optional)

## 6.1 Audit column rollout (ADR-016)

created_by and updated_by are **mandatory for new business tables**.

Existing tables may be migrated **incrementally**.

Do not schedule a large-scale migration solely to satisfy this standard. Add the
columns when a table is next altered for another reason.

Lookup tables (§8) are exempt — they are seeded infrastructure, not
user-authored business data.

---

# 7. Naming Convention

Tables

plural

Examples

users

products

offers

stores

Columns

snake_case

Foreign Keys

organization_id

store_id

offer_id

Pivot Tables

alphabetical

Example

offer_store

organization_user

---

# 8. Lookup Tables

The following are lookup tables (ADR-006).

countries

currencies

languages

timezones

tax_rates

payment_methods

shipping_methods

**notification_channels is NOT a lookup table.** Notification channels are an
enum — `NotificationType` (ADR-006, §9). Adding a channel means writing a
driver, so it can never be enabled by an operator without a release.

Lookup tables use

id

uuid

code

name

is_active

sort_order

Lookup tables use **is_active**, not a workflow `status` column (ADR-015). They
have no lifecycle to move through — they are either offered or they are not.
See §13.

---

# 9. Enum Usage

Enums are reserved only for immutable concepts — where adding a case requires
writing code to handle it.

Allowed

UserStatus

OfferStatus

OrderStatus

StoreStatus

DiscountType

CommissionType

MediaType

NotificationType

ActivityType

Forbidden

Country

Currency

Language

Timezone

Payment Method

Shipping Method

Tax Rate

Enum class names carry **no `Enum` suffix** (ADR-007). `OrderStatus`, never
`OrderStatusEnum`.

---

# 10. JSON Usage

JSON columns are allowed only for

settings

metadata

configuration

localized_content

Never store searchable business data inside JSON.

---

# 11. Soft Deletes

Business entities use Soft Delete.

Examples

Products

Stores

Organizations

Offers

Customers

Do NOT Soft Delete

Pivot tables

Logs

Audit tables

History tables

---

# 12. Audit Columns

Sensitive tables include

created_by

updated_by

deleted_by

Referenced to users.id

---

# 13. Status

Every **business entity** contains

status

Status values must be Enum backed.

Never use raw strings in application code.

**Lookup tables do not use `status`** — they use `is_active` (ADR-015, §8).

| | Column | Why |
|---|---|---|
| Business entity | `status` (enum-backed) | Has a lifecycle: draft → pending → active → archived |
| Lookup table | `is_active` (boolean) | Has no lifecycle. It is offered or it is not. |

The column is a string in the database and cast to an enum on the model. The
enum is the source of truth; the database stores only its value.

---

# 14. Versioning

Master Product

description_version

seo_version

content_version

Future support

Change history

Approval workflow

Rollback

---

# 15. Localization

Never duplicate tables.

Localized content belongs to translation tables.

Example

product_translations

Fields

language_id

title

slug

description

seo_title

seo_description

---

# 16. Money

Never use FLOAT.

Never use DOUBLE.

**Money is stored as an integer of minor units** (ADR-005).

```
1299.90 TL  →  129990
```

Examples: price, cost, commission amount, tax amount, discount amount.

`Currency.decimal_places` is the exponent for converting between major and
minor units. It is read per currency rather than assumed to be 2, so a
zero-decimal currency (JPY) works without auditing every call site.

**Why not DECIMAL for amounts.** `0.1 + 0.2 !== 0.3` in binary floating point,
and a DECIMAL column read into a PHP float reintroduces exactly that. On a
platform computing commission on every order the error compounds into real
money and into reconciliation disputes.

## 16.1 Where DECIMAL IS used

DECIMAL is correct for **rates and percentages**, which are ratios rather than
amounts:

- Exchange rates — `DECIMAL(20,10)`
- Tax rates
- Commission percentages
- Discount percentages

A rate multiplied against a large order total loses real money to binary
rounding, so the precision matters here too.

## 16.2 API representation

API responses format money as **decimal strings**, never numbers
(`005_API_Standards.md` §28):

```
"price": "1299.90"
```

Formatting happens at the API boundary. Storage stays integer.

---

# 17. Quantity

Inventory

DECIMAL(18,4)

Support

Weight

Length

Volume

Fractional quantities

---

# 18. Date Rules

Store timestamps in UTC.

Convert in application layer.

---

# 19. Index Strategy

Index

UUID

Slug

Status

Created At

Updated At

Foreign Keys

Search Columns

Composite indexes where required.

Never over-index.

---

# 20. Unique Constraints

Examples

users.email

stores.slug

organizations.tax_number

currencies.code

languages.code

---

# 21. Cascade Rules

Never use CASCADE DELETE **on business entities** (ADR-014).

Prefer

RESTRICT

or

SET NULL

Business data must not disappear unexpectedly.

## 21.1 Where CASCADE IS permitted

CASCADE is allowed for **dependent child records that cannot exist
independently** of their parent:

- Sessions
- Devices
- Temporary tokens
- Pivot tables

These carry no standalone business meaning. A session belonging to no user is
not data worth keeping — it is a row that can never be read, revoked or
displayed.

## 21.2 The distinction

| | Rule | Why |
|---|---|---|
| Business entity | never CASCADE | An organization must not vanish because a country row was deleted |
| Dependent child | CASCADE permitted | Orphaning it produces unreachable rows, not preserved evidence |
| Audit / history | never CASCADE, never soft delete | The trail must outlive the actor — use SET NULL |

Note the third row: audit and activity records use **SET NULL** on their actor
reference. Deleting an account must not erase the record of what that account
did.

---

# 22. History

Critical entities maintain history.

Products

Offers

Prices

SEO

Inventory

Commission

Orders

---

# 23. Attachments

Media files never stored inside tables.

Store only

Disk

Path

File Name

Mime

Size

Checksum

---

# 24. Search

Search indexes are handled by OpenSearch.

Never optimize database for full-text search.

---

# 25. Multi-tenancy

MarketplaceOS is NOT database-per-tenant.

Single database.

Shared catalog.

Organizations isolated by permissions.

---

# 26. Performance

Use eager loading.

Avoid N+1.

Index every FK.

Use cursor pagination where appropriate.

---

# 27. Migrations

One migration

One responsibility.

Never edit old migrations.

Create new migrations for changes.

---

# 28. Seeders

Seed only

Countries

Currencies

Languages

Permissions

Roles

System Settings

Never seed demo business data.

---

# 29. Architecture Rules

Products belong to Marketplace.

Offers belong to Stores.

Stores belong to Organizations.

Orders belong to Customers.

Commissions belong to Offers.

---

# 30. Database Decision Priority

When conflicts occur (ADR-003):

1. CLAUDE.md
2. Architecture_Decision_Record.md
3. 001_Architecture.md
4. 003_Database_Standards.md
5. 002_Coding_Standards.md
6. 004_Naming_Conventions.md
7. 005_API_Standards.md
8. Module Specifications

Sprint prompts never override documentation.

Never violate higher-level documents.

---

# Questions for Implementation

If any database decision is unclear:

- Stop implementation.
- Explain the ambiguity.
- Suggest the best approach.
- Wait for approval.

Never make assumptions.

---

END OF FILE