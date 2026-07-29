<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Filament\Resources\OfferResource;
use App\Modules\Offer\Presentation\Filament\Resources\OfferResource\Pages\ListOffers;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Admin panel — offer oversight, and the things it deliberately cannot do
|--------------------------------------------------------------------------
|
| Offers are not moderated (ADR-044): they go live the moment a seller saves
| them, and this surface is the reactive counterweight. So what is pinned here
| is mostly what is ABSENT — no create, no edit, no delete — plus the one lever
| that exists and the fact that lifting it restores the seller's own state.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * Put an already-authenticated admin into the platform Admin role.
 *
 * Takes the admin rather than calling `test()` internally: the acting-as helper
 * belongs to the test case, and reaching for it from a free function is the kind
 * of indirection that reads fine until someone moves the function.
 *
 * Named for this file because Pest shares ONE global function namespace across
 * the whole suite — a second `asPlatformAdmin` anywhere is a fatal redeclare,
 * which the per-file run cannot see and only the full run catches.
 */
function asOfferOversightAdmin(Admin $admin): Admin
{
    $admin->syncRoles([config('marketplace.roles.admin')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    return $admin;
}

it('lists every seller’s offers — oversight is cross-org', function (): void {
    asOfferOversightAdmin($this->actingAsAdmin());

    $first = Offer::factory()->forOrganization(1, 'org-a')->forVariant('v-1', 'p-1')->create();
    $second = Offer::factory()->forOrganization(2, 'org-b')->forVariant('v-2', 'p-2')->create();

    Livewire::test(ListOffers::class)->assertCanSeeTableRecords([$first, $second]);
});

it('suspends an offer with a required reason', function (): void {
    $admin = asOfferOversightAdmin($this->actingAsAdmin());
    $offer = Offer::factory()->create();

    Livewire::test(ListOffers::class)
        ->callTableAction('suspend', $offer, ['reason' => 'Aldatıcı fiyat']);

    expect($offer->fresh()->status)->toBe(OfferStatus::Suspended)
        ->and($offer->fresh()->suspended_by)->toBe($admin->getKey())
        ->and($offer->fresh()->suspension_reason)->toBe('Aldatıcı fiyat');
});

it('restores the seller’s own state when the suspension is lifted', function (): void {
    asOfferOversightAdmin($this->actingAsAdmin());
    $offer = Offer::factory()->paused()->create();

    Livewire::test(ListOffers::class)->callTableAction('suspend', $offer, ['reason' => 'İnceleme']);
    Livewire::test(ListOffers::class)->callTableAction('reinstate', $offer->fresh());

    // Back to paused, not to live: lifting undoes the admin's action, not the
    // seller's.
    expect($offer->fresh()->status)->toBe(OfferStatus::Paused);
});

it('offers no way to create, edit or delete a merchant’s listing', function (): void {
    asOfferOversightAdmin($this->actingAsAdmin());
    $offer = Offer::factory()->create();

    // Editing a merchant's price is not oversight — it is trading on their
    // behalf, and the audit entry would name the wrong party.
    expect(OfferResource::canCreate())->toBeFalse()
        ->and(OfferResource::canEdit($offer))->toBeFalse()
        ->and(OfferResource::canDelete($offer))->toBeFalse()
        ->and(array_keys(OfferResource::getPages()))->toBe(['index', 'view']);
});

it('gives Support the list but not the lever', function (): void {
    $support = $this->actingAsAdmin();
    $support->syncRoles([config('marketplace.roles.support')]);
    $support->refresh()->loadMissing('roles.permissions', 'permissions');

    $offer = Offer::factory()->create();

    expect(OfferResource::canViewAny())->toBeTrue();

    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$offer])
        ->assertTableActionHidden('suspend', $offer);
});

it('closes the area to a role that holds no offer permission', function (): void {
    $editor = $this->actingAsAdmin();
    $editor->syncRoles([config('marketplace.roles.editor')]);
    $editor->refresh()->loadMissing('roles.permissions', 'permissions');

    // A content editor has no business in the commercial record.
    expect(OfferResource::canViewAny())->toBeFalse();
});
