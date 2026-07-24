<?php

declare(strict_types=1);

namespace App\Modules\Organization;

use App\Modules\Organization\Domain\Contracts\OrganizationBankAccountRepositoryContract;
use App\Modules\Organization\Domain\Contracts\OrganizationDocumentRepositoryContract;
use App\Modules\Organization\Domain\Contracts\OrganizationInvitationRepositoryContract;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Contracts\OrganizationPlanRepositoryContract;
use App\Modules\Organization\Domain\Contracts\OrganizationRepositoryContract;
use App\Modules\Organization\Domain\Contracts\StoreOpeningRequestRepositoryContract;
use App\Modules\Organization\Application\Listeners\RecordCreatedStore;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Modules\Organization\Infrastructure\Authorization\OrganizationAuthorization;
use App\Modules\Organization\Infrastructure\Repositories\OrganizationBankAccountRepository;
use App\Modules\Organization\Infrastructure\Repositories\OrganizationDocumentRepository;
use App\Modules\Organization\Infrastructure\Repositories\OrganizationInvitationRepository;
use App\Modules\Organization\Infrastructure\Repositories\OrganizationMemberRepository;
use App\Modules\Organization\Infrastructure\Repositories\OrganizationPlanRepository;
use App\Modules\Organization\Infrastructure\Repositories\OrganizationRepository;
use App\Modules\Organization\Infrastructure\Repositories\StoreOpeningRequestRepository;
use App\Modules\Organization\Presentation\Policies\OrganizationBankAccountPolicy;
use App\Modules\Organization\Presentation\Policies\OrganizationDocumentPolicy;
use App\Modules\Organization\Presentation\Policies\OrganizationInvitationPolicy;
use App\Modules\Organization\Presentation\Policies\OrganizationMemberPolicy;
use App\Modules\Organization\Presentation\Policies\OrganizationPolicy;
use App\Modules\Organization\Presentation\Policies\StoreOpeningRequestPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Organization module wiring.
 *
 * Organization is the legal seller company (ADR-028). It owns Store Opening
 * Requests but never a Store — a Store is created by the Store module consuming
 * `StoreOpeningApproved`. Cross-module talk is via events; Organization imports
 * only Core, Shared, Localization and `app/Models/User`.
 *
 * @see docs/modules/Organization.md
 */
final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrganizationRepositoryContract::class, OrganizationRepository::class);
        $this->app->singleton(OrganizationPlanRepositoryContract::class, OrganizationPlanRepository::class);
        $this->app->singleton(OrganizationMemberRepositoryContract::class, OrganizationMemberRepository::class);
        $this->app->singleton(OrganizationInvitationRepositoryContract::class, OrganizationInvitationRepository::class);
        $this->app->singleton(OrganizationDocumentRepositoryContract::class, OrganizationDocumentRepository::class);
        $this->app->singleton(OrganizationBankAccountRepositoryContract::class, OrganizationBankAccountRepository::class);
        $this->app->singleton(StoreOpeningRequestRepositoryContract::class, StoreOpeningRequestRepository::class);

        // The cross-context authorization port (ADR-033 §9.1). Organization is
        // the single source of truth for memberships and capabilities; Store and
        // future seller-owned modules depend only on the Core contract.
        $this->app->singleton(OrganizationAuthorizationContract::class, OrganizationAuthorization::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Organization/migrations'));

        Gate::policy(Organization::class, OrganizationPolicy::class);
        // Member management is authorised by org CAPABILITIES (membership), not
        // Spatie permissions — see OrganizationMemberPolicy (ADR-030).
        Gate::policy(OrganizationMember::class, OrganizationMemberPolicy::class);
        Gate::policy(OrganizationInvitation::class, OrganizationInvitationPolicy::class);
        Gate::policy(OrganizationDocument::class, OrganizationDocumentPolicy::class);
        Gate::policy(OrganizationBankAccount::class, OrganizationBankAccountPolicy::class);
        Gate::policy(StoreOpeningRequest::class, StoreOpeningRequestPolicy::class);

        // The Store module reports back when it creates a store from an approved
        // request (ADR-032). Organization records the resulting store's UUID on
        // the request — the consumer direction, Store's events only (ADR-033).
        Event::subscribe(RecordCreatedStore::class);
    }

    /**
     * Permissions are DERIVED from a resource registration, never hand-listed.
     *
     * Phase 1: the admin surface. `organization` CRUD verbs plus the KYC
     * lifecycle abilities. `reinstate` is the un-suspend ability, kept distinct
     * from the soft-delete `restore` verb (§OrganizationPolicy). Seller-guard
     * abilities (view/update own) are registered when the seller API lands
     * (Phase 6); the lifecycle-review abilities for documents and store requests
     * arrive with Phases 4–5.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::resource('organization', [UserType::Admin]);

        PermissionRegistry::ability('organization.approve', [UserType::Admin]);
        PermissionRegistry::ability('organization.reject', [UserType::Admin]);
        PermissionRegistry::ability('organization.suspend', [UserType::Admin]);
        PermissionRegistry::ability('organization.reinstate', [UserType::Admin]);
        // Reviewing a company's KYC documents is a cross-org admin power (§4.2).
        PermissionRegistry::ability('organization.review_documents', [UserType::Admin]);
        // Deciding Store Opening Requests is an admin power (ADR-028).
        PermissionRegistry::ability('store_request.approve', [UserType::Admin]);
        PermissionRegistry::ability('store_request.reject', [UserType::Admin]);
        // Adjusting an org's store allowance (override / plan).
        PermissionRegistry::ability('organization.manage_limit', [UserType::Admin]);
    }
}
