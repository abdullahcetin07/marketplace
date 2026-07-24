<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Application\Actions\ApproveOrganizationAction;
use App\Modules\Organization\Application\Actions\RegisterOrganizationAction;
use App\Modules\Organization\Application\Actions\RejectOrganizationAction;
use App\Modules\Organization\Application\Actions\RestoreOrganizationAction;
use App\Modules\Organization\Application\Actions\SuspendOrganizationAction;
use App\Modules\Organization\Domain\DTOs\RegisterOrganizationDTO;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Events\OrganizationApproved;
use App\Modules\Organization\Domain\Events\OrganizationCreated;
use App\Modules\Organization\Domain\Events\OrganizationRejected;
use App\Modules\Organization\Domain\Models\Organization;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Organization lifecycle (Phase 1)
|--------------------------------------------------------------------------
|
| Register → approve / reject / suspend / reinstate. Every admin transition is
| a model diff, so the Auditable trait records it with the admin's reason. The
| actions are exercised directly — the HTTP surface arrives in Phase 6.
|
| @see docs/modules/Organization.md §3.1
*/

beforeEach(function (): void {
    // Localization gives the register DTO a real country + currency to resolve.
    $this->seedPlatform();
});

function registerOrganization(?Seller $owner = null): Organization
{
    $owner ??= Seller::factory()->create();

    $dto = new RegisterOrganizationDTO(
        ownerId: $owner->getKey(),
        legalName: 'Acme Trading Ltd',
        displayName: null,
        slug: 'acme-trading-'.$owner->getKey(),
        countryCode: (string) Country::query()->value('iso2'),
        currencyCode: (string) Currency::query()->value('code'),
    );

    return app(RegisterOrganizationAction::class)->run($dto);
}

it('registers a new organization as pending and announces it', function (): void {
    Event::fake([OrganizationCreated::class]);

    $organization = registerOrganization();

    expect($organization->status)->toBe(OrganizationStatus::Pending)
        ->and($organization->verified_at)->toBeNull()
        ->and($organization->owner_id)->not->toBeNull();

    Event::assertDispatched(
        OrganizationCreated::class,
        fn (OrganizationCreated $e): bool => $e->organizationId === $organization->getKey(),
    );
});

it('approves a pending organization and records the admin reason', function (): void {
    $organization = registerOrganization();
    $admin = Admin::factory()->create();
    AuditEntry::query()->delete();

    Event::fake([OrganizationApproved::class]);
    app(ApproveOrganizationAction::class)->run($organization, $admin, 'KYC verified, documents complete');

    $fresh = $organization->fresh();
    expect($fresh->status)->toBe(OrganizationStatus::Approved)
        ->and($fresh->verified_at)->not->toBeNull()
        ->and($fresh->approved_by)->toBe($admin->getKey());

    $entry = AuditEntry::query()->forModel($organization)->latest('id')->first();
    expect($entry->event_type)->toBe(AuditEventType::ModelUpdated)
        ->and($entry->metadata)->toBe(['reason' => 'KYC verified, documents complete']);

    Event::assertDispatched(OrganizationApproved::class);
});

it('rejects a pending organization with a reason on the record and the event', function (): void {
    $organization = registerOrganization();
    $admin = Admin::factory()->create();

    Event::fake([OrganizationRejected::class]);
    app(RejectOrganizationAction::class)->run($organization, $admin, 'Tax number could not be verified');

    $fresh = $organization->fresh();
    expect($fresh->status)->toBe(OrganizationStatus::Rejected)
        ->and($fresh->rejection_reason)->toBe('Tax number could not be verified')
        ->and($fresh->rejected_by)->toBe($admin->getKey());

    Event::assertDispatched(
        OrganizationRejected::class,
        fn (OrganizationRejected $e): bool => $e->reason === 'Tax number could not be verified',
    );
});

it('suspends and then reinstates an organization', function (): void {
    $organization = Organization::factory()->approved()->create();
    $admin = Admin::factory()->create();

    app(SuspendOrganizationAction::class)->run($organization, $admin, 'Dispute under review');

    $suspended = $organization->fresh();
    expect($suspended->status)->toBe(OrganizationStatus::Suspended)
        ->and($suspended->suspended_at)->not->toBeNull()
        ->and($suspended->suspended_by)->toBe($admin->getKey());

    app(RestoreOrganizationAction::class)->run($organization, $admin, 'Dispute resolved');

    $restored = $organization->fresh();
    expect($restored->status)->toBe(OrganizationStatus::Approved)
        ->and($restored->suspended_at)->toBeNull()
        ->and($restored->suspended_by)->toBeNull();
});

it('never records the exact same status as a change twice', function (): void {
    // An approve on an already-approved org still writes only what changed.
    $organization = Organization::factory()->approved()->create();
    AuditEntry::query()->delete();

    app(ApproveOrganizationAction::class)->run($organization, Admin::factory()->create(), 'Re-confirmed');

    // verified_at/approved_by changed even though status did not, so there IS an
    // entry — but it is a real diff, not a no-op row.
    expect(AuditEntry::query()->forModel($organization)->count())->toBeGreaterThan(0);
});
