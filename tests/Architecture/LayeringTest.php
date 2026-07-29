<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture Tests — Layering
|--------------------------------------------------------------------------
|
| These are the rules that keep the architecture from eroding. Documentation
| describing a layering rule is a suggestion; a failing test is a rule.
|
| Every assertion here corresponds to a decision recorded in
| docs/001_Architecture.md. If one of these starts failing, the question is not
| "how do I silence it" but "did we mean to change that decision".
|
*/

arch('the Domain layer depends on nothing framework-specific')
    ->expect('App\Core\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades\DB',
    ])
    ->ignoring([
        // The persistence port necessarily names Model in its signatures; it
        // is the seam, not a violation of it.
        'App\Core\Domain\Contracts\RepositoryContract',
        // BaseException renders itself into an HTTP response, which is the
        // only reason it may see Request.
        'App\Core\Domain\Exceptions\BaseException',
        // BaseDTO::fromRequest() is a convenience at the boundary. It reads
        // validated() only — the DTO itself stays framework-agnostic.
        'App\Core\Domain\DataTransferObjects\BaseDTO',
    ]);

arch('the Domain layer never depends on Infrastructure or Presentation')
    ->expect('App\Core\Domain')
    ->not->toUse([
        'App\Core\Infrastructure',
        'App\Core\Presentation',
    ]);

arch('the Application layer never depends on Presentation')
    ->expect('App\Core\Application')
    ->not->toUse('App\Core\Presentation');

/*
|--------------------------------------------------------------------------
| Module isolation
|--------------------------------------------------------------------------
|
| THE RULE: modules never import each other. Cross-module communication goes
| through domain events. @see App\Core\Domain\Events\BaseEvent
|
| One assertion per module, listing the modules it may not touch. Written out
| explicitly rather than generated, so adding a module forces a deliberate
| decision about what it is allowed to see — a generated loop would silently
| cover a new module with rules nobody reviewed.
|
| Localization is the one permitted dependency: Country, Currency and Language
| are platform-wide reference data that every module reads, and duplicating
| them per module would defeat the point of promoting them to tables.
| @see docs/001_Architecture.md §"Enums vs lookup tables"
*/

arch('Identity does not depend on other modules')
    ->expect('App\Modules\Identity')
    ->not->toUse([
        'App\Modules\Settings',
        'App\Modules\Audit',
        'App\Modules\Activity',
        'App\Modules\Media',
        'App\Modules\Notification',
        'App\Modules\Organization',
    ]);

arch('Localization depends on no other module at all')
    ->expect('App\Modules\Localization')
    ->not->toUse([
        'App\Modules\Identity',
        'App\Modules\Settings',
        'App\Modules\Audit',
        'App\Modules\Activity',
        'App\Modules\Media',
        'App\Modules\Notification',
        'App\Modules\Organization',
    ]);

/*
| Audit is the platform's forensic event store (ADR-027). It records security
| events raised across the platform, so — like Activity — it SUBSCRIBES to other
| modules' events and necessarily imports them. That is the consumer direction:
| Audit knows the producers' event contracts, never the reverse.
|
| The exception is scoped to Domain\Events, exactly as Activity's is. Importing
| a model, service or action from another module would be a real leak and still
| fails. Only Identity emits security events today; the rule is widened
| module-by-module as producers are added, so each addition is a decision.
*/
arch('Audit depends on Identity only through its events')
    ->expect('App\Modules\Audit')
    ->not->toUse([
        'App\Modules\Identity\Domain\Models',
        'App\Modules\Identity\Domain\DTOs',
        'App\Modules\Identity\Domain\Exceptions',
        'App\Modules\Identity\Application',
        'App\Modules\Identity\Infrastructure',
        'App\Modules\Identity\Presentation',
        'App\Modules\Settings',
        'App\Modules\Activity',
        'App\Modules\Media',
        'App\Modules\Notification',
    ]);

/*
| Activity SUBSCRIBES to Identity's events, so it necessarily imports them.
| That is the correct direction — the consumer knows the producer's event
| contract, never the reverse — and it is what lets Identity stay ignorant of
| whether anything is listening.
|
| The rule is therefore stated precisely rather than loosened: Activity may see
| Identity's Domain\Events and nothing else. Importing a model, service or
| action from Identity would be a real violation and still fails.
*/
arch('Activity depends on Identity only through its events')
    ->expect('App\Modules\Activity')
    ->not->toUse([
        'App\Modules\Identity\Domain\Models',
        'App\Modules\Identity\Domain\Data',
        'App\Modules\Identity\Domain\Exceptions',
        'App\Modules\Identity\Application',
        'App\Modules\Identity\Infrastructure',
        'App\Modules\Identity\Presentation',
        'App\Modules\Settings',
        'App\Modules\Audit',
        'App\Modules\Media',
        'App\Modules\Notification',
    ]);

/*
| Settings uses the Audit trait on its model — a deliberate, documented
| exception rather than a leak. Settings changes are exactly the kind of thing
| a dispute turns on ("who enabled guest checkout?"), and the alternative is
| Audit reaching INTO Settings, which is the worse direction.
*/
arch('Settings depends only on Audit')
    ->expect('App\Modules\Settings')
    ->not->toUse([
        'App\Modules\Identity',
        'App\Modules\Activity',
        'App\Modules\Media',
        'App\Modules\Notification',
        'App\Modules\Organization',
    ]);

/*
| Organization is a business module that, like Settings, uses the Audit trait on
| its aggregates (every state change is dispute evidence, ADR-027) and reads the
| Localization tables (country/currency) — the two permitted dependencies. It
| talks to Identity through events + the shared `app/Models/User`.
|
| It also SUBSCRIBES to Store's `StoreCreated` to record `created_store_uuid`
| (ADR-032 back-reference), so it imports Store's Domain\Events — the consumer
| direction, scoped exactly as Audit/Activity scope theirs. Importing a Store
| model, service or action would be a real leak (ADR-033) and still fails.
*/
arch('Organization depends only on Audit, Localization, and Store events')
    ->expect('App\Modules\Organization')
    ->not->toUse([
        'App\Modules\Identity',
        'App\Modules\Settings',
        'App\Modules\Activity',
        'App\Modules\Media',
        'App\Modules\Notification',
        'App\Modules\Store\Domain\Models',
        'App\Modules\Store\Domain\Contracts',
        'App\Modules\Store\Domain\Enums',
        'App\Modules\Store\Application',
        'App\Modules\Store\Infrastructure',
        'App\Modules\Store\Presentation',
    ]);

/*
| Store is the storefront — an independent bounded context (ADR-033). It is
| created by consuming Organization's `StoreOpeningApproved` (ADR-028/032), so it
| imports Organization's Domain\Events — the consumer direction. It reads the
| Localization tables (language/currency/timezone) and uses the Audit trait on
| its aggregate. It references its owning company by id/uuid and NEVER imports an
| Organization model, service, repository, DTO or enum (ADR-033); that dependency
| flows through the event payload and, from Phase 2, a Core query contract.
*/
arch('Store depends only on Audit, Localization, and Organization events')
    ->expect('App\Modules\Store')
    ->not->toUse([
        'App\Modules\Identity',
        'App\Modules\Settings',
        'App\Modules\Activity',
        'App\Modules\Media',
        'App\Modules\Notification',
        'App\Modules\Organization\Domain\Models',
        'App\Modules\Organization\Domain\Contracts',
        'App\Modules\Organization\Domain\Enums',
        'App\Modules\Organization\Domain\DTOs',
        'App\Modules\Organization\Domain\Exceptions',
        'App\Modules\Organization\Application',
        'App\Modules\Organization\Infrastructure',
        'App\Modules\Organization\Presentation',
    ]);

/*
| Catalog is the shared product catalog — the first module built after the
| Organization/Store freeze, and the strictest case of ADR-033 yet (restated as
| ADR-040). It subscribes to NOTHING: unlike Store it is not created by another
| module's event, so it has no consumer-direction exception at all. The proposing
| company travels as `proposed_by_org_uuid` — a bare string — and downstream
| contexts read Catalog through the Core `CatalogQueryContract`.
|
| Its two permitted dependencies are the platform-wide ones every module has:
| Localization (reference data) and the Audit trait on its Product aggregate
| (ADR-027 — a catalog entry is a curated asset; who changed what matters).
| Media is reached through `App\Shared\Traits\HasMedia`, not by importing the
| Media module, so Media stays on the forbidden list.
|
| Store is listed WHOLESALE, with no events escape hatch: ADR-041 rules that
| Catalog registers no storefront contributor in Phase 1 and touches neither
| Store nor the storefront. If that changes it changes by ADR, not by import.
*/
arch('Catalog depends only on Audit and Localization, and subscribes to nothing')
    ->expect('App\Modules\Catalog')
    ->not->toUse([
        'App\Modules\Identity',
        'App\Modules\Settings',
        'App\Modules\Activity',
        'App\Modules\Media',
        'App\Modules\Notification',
        'App\Modules\Organization',
        'App\Modules\Store',
    ]);

/*
| Offer is what makes the shared catalog sellable (ADR-042), and it is the
| strictest boundary on the platform: it imports NOTHING. Unlike Store it is not
| created by another module's event, and unlike Catalog it genuinely needs to
| read three other contexts — so the temptation to import is real and the rule is
| stated wholesale, with no events escape hatch.
|
| Everything it needs arrives through Core contracts: CatalogQueryContract +
| CatalogBrowseContract (is this variant real, is its product published, what can
| the seller pick), OrganizationAuthorizationContract (tenancy),
| StoreQueryContract (the storefront it is attributed to). Catalog's product
| lifecycle reaches it as domain events resolved by name through the container,
| never by importing Catalog's event classes — which is why Catalog is absent
| from the permitted list rather than scoped to Domain\Events the way Audit and
| Activity scope theirs.
|
| Its two permitted dependencies are the platform-wide ones every module has:
| Localization (money renders against the Currency model) and the Audit trait on
| the Offer aggregate (ADR-027 — a price change is dispute evidence).
*/
arch('Offer imports no other module at all')
    ->expect('App\Modules\Offer')
    ->not->toUse([
        'App\Modules\Identity',
        'App\Modules\Settings',
        'App\Modules\Activity',
        'App\Modules\Media',
        'App\Modules\Notification',
        'App\Modules\Organization',
        'App\Modules\Store',
        'App\Modules\Catalog',
    ]);

/*
| The mirror: nothing may reach INTO Offer either. Order, Inventory and Search
| will read it through the Core `OfferQueryContract` and its events when they
| exist; until then the only correct number of importers is zero, and stating it
| here means the first accidental import fails the build rather than quietly
| establishing a precedent. Same shape as Catalog's rule below.
*/
arch('no module depends on Offer')
    ->expect('App\Modules\Offer')
    ->toOnlyBeUsedIn([
        'App\Modules\Offer',
        // The panel providers register each module's Filament resources
        // explicitly, per panel — the composition root, not a module reaching
        // into another module.
        'App\Providers\Filament',
        'Database\Modules\Offer',
        'Tests\Modules\Offer',
    ]);

/*
| The mirror of the rule above: nothing built so far may reach INTO Catalog.
| Offer, Inventory and Search will read it through the Core contract when they
| exist; until then the only correct number of importers is zero, and stating it
| here means the first accidental import fails the build rather than quietly
| establishing a precedent.
*/
arch('no existing module depends on Catalog')
    ->expect('App\Modules\Catalog')
    ->toOnlyBeUsedIn([
        'App\Modules\Catalog',
        // The panel providers register each module's Filament resources
        // explicitly, per panel — that is the composition root, not a module
        // reaching into another module.
        'App\Providers\Filament',
        // A module's own migrations, factories, seeders and tests are part of
        // the module; they live under database/ and tests/ only because the
        // framework looks for them there.
        'Database\Modules\Catalog',
        'Tests\Modules\Catalog',
    ]);

arch('Core never depends on a module')
    ->expect('App\Core')
    ->not->toUse('App\Modules')
    // SetLocale resolves the language for the request; it is presentation
    // glue and has nowhere else to live.
    ->ignoring('App\Core\Presentation\Middleware\SetLocale');

/*
|--------------------------------------------------------------------------
| ADR-019 — Domain layer purity
|--------------------------------------------------------------------------
|
| The Domain layer may not reach the container for infrastructure. The rule
| covers global helper FUNCTIONS as well as Facade classes — a helper that
| resolves the same binding is the same violation.
|
| Allowed:   now(), config()   — a clock reading and a static array
| Forbidden: cache(), request(), encrypt(), decrypt()
|
| Where the work belongs instead:
|   caching    -> Infrastructure repositories
|   encryption -> Infrastructure casts
|   request    -> Presentation, pushed inward as AuditContext
*/

arch('no forbidden infrastructure helpers in any Domain layer')
    ->expect(['cache', 'request', 'encrypt', 'decrypt'])
    ->not->toBeUsedIn([
        'App\Core\Domain',
        'App\Modules\Activity\Domain',
        'App\Modules\Audit\Domain',
        'App\Modules\Identity\Domain',
        'App\Modules\Localization\Domain',
        'App\Modules\Media\Domain',
        'App\Modules\Notification\Domain',
        'App\Modules\Organization\Domain',
        'App\Modules\Settings\Domain',
        'App\Modules\Store\Domain',
        'App\Modules\Catalog\Domain',
        'App\Modules\Offer\Domain',
    ]);

arch('no encryption Facades in any Domain layer')
    ->expect(['Illuminate\Support\Facades\Crypt', 'Illuminate\Support\Facades\Cache'])
    ->not->toBeUsedIn([
        'App\Core\Domain',
        'App\Modules\Localization\Domain',
        'App\Modules\Settings\Domain',
        'App\Modules\Audit\Domain',
    ]);

/*
|--------------------------------------------------------------------------
| ADR-021 — DTO naming
|--------------------------------------------------------------------------
|
| Suffix `DTO`, directory `Domain/DTOs`. "Data" is forbidden as a class-name
| suffix; `DataTransferObjects` is permitted only for the shared base class.
*/

it('has no Domain/Data directories left', function (): void {
    // Resolved from this file, not app_path(): architecture tests are not bound
    // to Tests\TestCase (see tests/Pest.php), so the application container may
    // not be booted when this runs. Under random execution order that made the
    // helper throw or not depending on what ran first.
    $modules = dirname(__DIR__, 2).'/app/Modules';

    expect(glob($modules.'/*/Domain/Data', GLOB_ONLYDIR))->toBe([]);
})->group('arch');

arch('every module DTO carries the DTO suffix')
    ->expect('App\Modules')
    ->classes()
    ->toHaveSuffix('DTO')
    ->ignoring([
        // Everything that is not a DTO. Narrowed by namespace rather than
        // listed class by class, so a new DTO is covered automatically.
        'App\Modules\Activity',
        'App\Modules\Audit',
        'App\Modules\Localization',
        'App\Modules\Media',
        'App\Modules\Notification',
        'App\Modules\Settings',
        'App\Modules\Identity\Application',
        'App\Modules\Identity\Domain\Models',
        'App\Modules\Identity\Domain\Events',
        'App\Modules\Identity\Domain\Exceptions',
        'App\Modules\Identity\Infrastructure',
        'App\Modules\Identity\Presentation',
        'App\Modules\Identity\IdentityServiceProvider',
        // Organization — non-DTO namespaces ignored so its Domain\DTOs stay
        // covered (they must carry the suffix), like Identity above.
        'App\Modules\Organization\Application',
        'App\Modules\Organization\Domain\Models',
        'App\Modules\Organization\Domain\Enums',
        'App\Modules\Organization\Domain\Events',
        'App\Modules\Organization\Domain\Contracts',
        'App\Modules\Organization\Domain\Exceptions',
        'App\Modules\Organization\Infrastructure',
        'App\Modules\Organization\Presentation',
        'App\Modules\Organization\OrganizationServiceProvider',
        // Store — non-DTO namespaces ignored so its Domain\DTOs stay covered
        // (they must carry the suffix) once they land in later phases.
        'App\Modules\Store\Application',
        'App\Modules\Store\Domain\Models',
        'App\Modules\Store\Domain\Enums',
        'App\Modules\Store\Domain\Events',
        'App\Modules\Store\Domain\Contracts',
        'App\Modules\Store\Domain\Exceptions',
        'App\Modules\Store\Infrastructure',
        'App\Modules\Store\Presentation',
        'App\Modules\Store\StoreServiceProvider',
        // Catalog — non-DTO namespaces ignored so its Domain\DTOs stay covered
        // (they must carry the suffix), like the modules above.
        'App\Modules\Catalog\Application',
        'App\Modules\Catalog\Domain\Models',
        'App\Modules\Catalog\Domain\Enums',
        'App\Modules\Catalog\Domain\Events',
        'App\Modules\Catalog\Domain\Contracts',
        'App\Modules\Catalog\Domain\Concerns',
        'App\Modules\Catalog\Domain\Exceptions',
        'App\Modules\Catalog\Infrastructure',
        'App\Modules\Catalog\Presentation',
        'App\Modules\Catalog\CatalogServiceProvider',
        // Offer — non-DTO namespaces ignored so its Domain\DTOs stay covered
        // (they must carry the suffix), like the modules above.
        'App\Modules\Offer\Application',
        'App\Modules\Offer\Domain\Models',
        'App\Modules\Offer\Domain\Enums',
        'App\Modules\Offer\Domain\Events',
        'App\Modules\Offer\Domain\Contracts',
        'App\Modules\Offer\Domain\Exceptions',
        'App\Modules\Offer\Infrastructure',
        'App\Modules\Offer\Presentation',
        'App\Modules\Offer\OfferServiceProvider',
    ]);

arch('shared enums stay free of dependencies')
    ->expect('App\Shared\Enums')
    ->not->toUse([
        'App\Core',
        'Illuminate\Database',
    ])
    ->ignoring([
        'App\Shared\Enums\UserType',          // maps actor types to their models
        'App\Shared\Enums\NotificationType',  // maps channels to their drivers
    ]);
