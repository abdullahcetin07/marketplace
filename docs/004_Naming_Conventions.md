# MarketplaceOS Naming Conventions
Version: 1.1

Synchronized with the Architecture Decision Record (ADR-007, ADR-008).

---

# 1. Purpose

This document defines naming rules.

Consistency is mandatory.

This document sits at position 6 in the precedence chain (ADR-003).

---

# 2. Classes

Singular

Examples

Product

Offer

Store

Organization

---

# 3. Models

Singular

Product

Offer

Inventory

---

# 4. Tables

Plural

products

offers

stores

organizations

---

# 5. Controllers

Suffix

Controller

Examples

ProductController

OfferController

---

# 6. Services

Suffix

Service

Examples

ProductService

OfferService

---

# 7. Interfaces

Suffix

Contract

Example

ProductRepositoryContract

OrganizationRepositoryContract

Editorial correction: version 1.0 of this document said "Prefix" while giving a
suffixed example. The example was correct and matches the existing
`RepositoryContract`.

---

# 8. Repositories

Suffix

Repository

ProductRepository

---

# 9. Policies

Suffix

Policy

ProductPolicy

---

# 10. Events

Past Tense

ProductCreated

OfferUpdated

StoreDeleted

---

# 11. Jobs

Imperative

GenerateSeo

OptimizeImage

SendEmail

---

# 12. Commands

Verb First

ImportProducts

SyncOffers

GenerateSitemap

---

# 13. DTO

Suffix

**DTO** (ADR-021)

Correct

LoginDTO

RegisterUserDTO

CreateProductDTO

OfferPriceDTO

Wrong

LoginData

RegisterUserData

CreateProductData

Directory

`{Module}/Domain/DTOs/`

"Data" is forbidden as a DTO class suffix (§30).

`DataTransferObjects` is allowed **only** for shared infrastructure if
necessary — it names a pattern rather than describing a vague noun. The base
class `App\Core\Domain\DataTransferObjects\BaseDTO` is the one sanctioned use.

---

# 14. Enums

**No suffix** (ADR-007).

Correct

OrderStatus

OfferStatus

StoreStatus

NotificationType

MediaType

Wrong

OrderStatusEnum

OfferStatusEnum

The type is already declared at every use site. `StatusEnum` and
`SettingTypeEnum` read worse than `Status` and `SettingType`, and the suffix
carries no information the reader lacks.

---

# 15. Requests

Suffix

Request

CreateProductRequest

UpdateOfferRequest

---

# 16. Resources

Suffix

Resource

ProductResource

OfferResource

---

# 17. Collections

Suffix

Collection

ProductCollection

---

# 18. Traits

Prefix

Has

Examples

HasSlug

HasMedia

HasSeo

---

# 19. Migrations

Laravel default

create_products_table

add_slug_to_products_table

---

# 20. Routes

Plural

/products

/offers

/orders

---

# 21. API

**snake_case JSON** (ADR-008).

Example

product_name

created_at

offer_count

current_page

per_page

Never use camelCase in REST responses. Request query parameters and request
bodies follow the same rule, so a field round-trips unchanged.

This matches the database column names, which removes an entire class of
mapping bug at the resource boundary.

---

# 22. Database

snake_case

created_at

updated_at

organization_id

---

# 23. Variables

camelCase

$productPrice

$organizationId

---

# 24. Constants

UPPER_CASE

MAX_UPLOAD_SIZE

DEFAULT_LANGUAGE

---

# 25. Environment

UPPER_CASE

APP_NAME

CACHE_DRIVER

QUEUE_CONNECTION

---

# 26. Translation Keys

dot.notation

product.created

offer.updated

organization.deleted

---

# 27. File Names

PascalCase

ProductService.php

OfferRepository.php

---

# 28. Test Classes

Suffix

Test

ProductServiceTest

OfferApiTest

---

# 29. Documentation

Four categories, each with its own convention (ADR-022). **Existing files are
not renamed** — this standard describes the structure in use.

| Category | Convention | Examples |
|---|---|---|
| Governing documents | `NNN_PascalCase_With_Underscores.md` | `001_Architecture.md`, `003_Database_Standards.md` |
| Architecture Decision Records | `PascalCase_With_Underscores.md` | `Architecture_Decision_Record.md` |
| Topic and module documentation | `lowercase-with-hyphens.md` | `authentication.md`, `error-handling.md` |
| Directory READMEs | `README.md` | — |

The numeric prefix on governing documents encodes **precedence order**
(ADR-003), which is load-bearing information rather than decoration.

No build step or tool consumes these filenames.

---

# 30. Forbidden

Never use

Manager

Handler

Util

Helper

Misc

Temp

Test123

NewClass

Data

Info

Object

unless there is a very clear architectural reason.

## 30.1 The "Data" carve-out (ADR-021)

**`Data` is forbidden as a class-name suffix.** `LoginData` is wrong;
`LoginDTO` is right.

**`DataTransferObjects` is permitted** for shared infrastructure only. It names
a pattern rather than describing a vague noun, which is the distinction §30 is
reaching for. Sanctioned use: `App\Core\Domain\DataTransferObjects\BaseDTO`.

Module-level DTO directories use `Domain/DTOs/`, never `Domain/Data/`.

---

END OF FILE