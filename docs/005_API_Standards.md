# MarketplaceOS API Standards
Version: 1.1

Synchronized with the Architecture Decision Record (ADR-005, 008, 009, 010,
017).

---

# 1. Purpose

This document defines the API standards used throughout MarketplaceOS.

Every REST endpoint must follow these rules.

This document sits at position 7 in the precedence chain (ADR-003).

**JSON casing is snake_case throughout** (ADR-008). Every example in this
document uses it.

---

# 2. API Philosophy

MarketplaceOS is API First.

All business operations must be accessible through APIs.

The Admin Panel, Seller Panel, Mobile Applications and future integrations must consume the same APIs whenever practical.

---

# 3. API Versioning

Current Version

/api/v1

Examples

GET /api/v1/products

POST /api/v1/offers

PUT /api/v1/stores/{uuid}

Future versions

/api/v2

Never break existing API contracts.

---

# 4. Public Identifiers

Never expose numeric IDs.

Only expose UUIDs.

Correct

/products/8e2b9d87...

Wrong

/products/1542

---

# 5. HTTP Methods

GET

Retrieve resources.

POST

Create resources.

PUT

Replace resources.

PATCH

Partial update.

DELETE

Soft delete where applicable.

---

# 6. Standard Success Response

{
    "success": true,
    "message": "Product created successfully.",
    "data": {},
    "meta": {}
}

---

# 7. Standard Error Response

**Canonical error envelope** (ADR-009). This is the only error shape. Every
failure — validation, authorization, domain, framework — uses it.

{
    "success": false,
    "code": "VALIDATION_ERROR",
    "message": "Validation failed.",
    "errors": {
        "email": [
            "The email field is required."
        ]
    }
}

`errors` is included **only when applicable** — a 422 carries it, a 404 does
not.

`code` is always present and machine-readable. See §25.

A client needs exactly one error shape to handle. Two shapes means every caller
writes a branch, and the branch is wrong somewhere.

---

# 8. Pagination

Always use Laravel pagination.

Response

{
    "success": true,
    "data": [],
    "meta": {
        "current_page": 1,
        "per_page": 20,
        "total": 500,
        "last_page": 25
    }
}

---

# 9. Filtering

Use query parameters.

Examples

GET /products?status=active

GET /products?category=vitamin

GET /offers?store=turuncukasa

GET /orders?customer=uuid

---

# 10. Sorting

sort=name

sort=-price

sort=created_at

Prefix

-

means descending.

---

# 11. Searching

Search is a **query parameter on the resource collection**, not a separate
endpoint.

GET /products?search=omega

Never create dedicated search endpoints such as `/search/products`.

The OpenSearch cluster backs this parameter internally; it is an implementation
detail of the collection endpoint, not a second public surface.

> **Advanced search** — faceting, aggregations, relevance tuning — is
> **DEFERRED** past Foundation (ADR-017).

---

# 12. Includes

Support includes.

Example

GET /products/{uuid}?include=offers,brand,images

Prevent N+1 queries.

---

# 13. Sparse Fields

Support field selection.

Example

GET /products?fields=name,slug

---

# 14. Validation Errors

Return HTTP 422.

Never return HTTP 200 for validation failures.

---

# 15. Authentication

Laravel Sanctum.

Bearer Token.

Authorization

Bearer xxxxxxxxx

---

# 16. Authorization

Policies only.

Never authorize in Controllers.

---

# 17. Status Codes

200 OK

201 Created

202 Accepted

204 No Content

400 Bad Request

401 Unauthorized

403 Forbidden

404 Not Found

409 Conflict

422 Validation Error

429 Too Many Requests

500 Internal Error

---

# 18. Resources

Always use Laravel API Resources.

**REST endpoints never return Eloquent models directly** (ADR-010).

Presentation layers that are not REST — Filament, Console, Admin UI — may use
Eloquent models where appropriate. The boundary is the transport, not the
layer. See `002_Coding_Standards.md` §6.1.

---

# 19. Controllers

Controllers must be thin.

Allowed

Validation

Service Call

Return Resource

Forbidden

Business Logic

Database Queries

Calculations

---

# 20. Services

Business logic belongs inside Services.

Controllers call Services.

Services return DTOs.

---

# 21. Repositories

Repositories handle persistence only.

Never place business logic inside repositories.

---

# 22. DTO

Input DTO

Output DTO

Never pass Request objects to Services.

---

# 23. Idempotency

> **DEFERRED** past Foundation (ADR-017). The endpoints that need it —
> Payments, Orders, Imports — do not exist yet.

Support Idempotency-Key for critical POST endpoints.

Example

Payments

Orders

Imports

---

# 24. Rate Limiting

Public API

60 requests/minute

Authenticated

120 requests/minute

Sensitive endpoints

Custom limits

---

# 25. Error Codes

Every error carries a machine-readable `code`, in the canonical envelope
defined in §7 (ADR-009).

Example — no `errors` key, because none applies:

{
    "success": false,
    "code": "PRODUCT_NOT_FOUND",
    "message": "Product not found."
}

Example — 422, with `errors`:

{
    "success": false,
    "code": "VALIDATION_ERROR",
    "message": "Validation failed.",
    "errors": {
        "email": ["The email field is required."]
    }
}

Codes are UPPER_SNAKE_CASE and stable. A client branches on `code`, never on
`message` — messages are translated and will change.

---

# 26. Localization

Accept-Language header supported.

Default

tr

Examples

Accept-Language: en

Accept-Language: tr

---

# 27. Time Format

ISO 8601

Example

2026-07-22T14:20:11Z

---

# 28. Money

Always return decimal strings (ADR-005).

Correct

"price": "1299.90"

Wrong

1299.9

Money is **stored** as an integer of minor units (`003_Database_Standards.md`
§16). Formatting to a decimal string happens at the resource boundary, using the
currency's own `decimal_places`.

A JSON number would be parsed as a float by most clients, reintroducing exactly
the precision loss integer storage exists to prevent.

Always pair an amount with its currency code:

{
    "price": "1299.90",
    "currency": "TRY"
}

---

# 29. UUID

UUID exposed.

Internal IDs hidden.

---

# 30. Soft Deleted Resources

Never return deleted resources unless explicitly requested.

Example

?with_deleted=1

Admin only.

---

# 31. File Upload

Multipart Form Data.

Return Media Resource.

Never expose physical storage paths.

---

# 32. Bulk Operations

> **DEFERRED** past Foundation (ADR-017).

Support bulk endpoints.

Example

POST /products/bulk

POST /offers/bulk

---

# 33. API Documentation

Every endpoint must include

Purpose

Authentication

Permissions

Request Example

Response Example

Validation Rules

Error Codes

---

# 34. Deprecation

Deprecated endpoints must include

Deprecation Header

Removal Date

Migration Guide

---

# 35. Webhooks

> **DEFERRED** past Foundation (ADR-017).

Every webhook must contain

Event Name

UUID

Timestamp

Signature

Version

---

# 36. Security

HTTPS only.

No secrets in payload.

Sanitize inputs.

Validate every request.

---

# 37. Logging

Log

Errors

Warnings

Security events

Rate limit violations

Never log passwords or tokens.

---

# 38. API Decision Priority

If conflicts occur (ADR-003)

1. CLAUDE.md
2. Architecture_Decision_Record.md
3. 001_Architecture.md
4. 003_Database_Standards.md
5. 002_Coding_Standards.md
6. 004_Naming_Conventions.md
7. 005_API_Standards.md
8. Module Specifications

Sprint prompts never override documentation.

---

# Questions for Implementation

If any API behavior is unclear:

- Stop implementation.
- Explain the ambiguity.
- Suggest the best solution.
- Wait for approval.

Never make assumptions.

---

END OF FILE