<?php

declare(strict_types=1);

use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationKyc;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages\ViewOrganization;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — company verification (KYC)
|--------------------------------------------------------------------------
|
| The screen is a modal header action on the company page. It owns no rule: the
| schema mirrors SubmitKycRequest, the gate is the same `manageKyc` capability,
| and the write is SubmitKycAction — which upserts one row per organization, so
| the same button serves the first submission and every later correction.
|
| The national id is the interesting part. It is encrypted at rest and excluded
| from the audit trail, so the form never renders it back; "left blank" has to
| mean "keep what is on file" rather than "erase it", and that is asserted here.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * A company the given seller owns, with the owner membership row that carries
 * the capabilities.
 */
function ownedOrganization(App\Models\Seller $seller): Organization
{
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    return $organization;
}

it('submits company verification through the action', function (): void {
    $seller = $this->actingAsSeller();
    $organization = ownedOrganization($seller);

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->callAction('submitKyc', [
            'tax_number' => '1234567890',
            'registration_number' => 'TR-99887',
            'authorized_person_name' => 'Ayşe Yılmaz',
            'authorized_person_national_id' => '12345678901',
            'metadata' => ['mersis' => '0123456789012345'],
        ])
        ->assertHasNoActionErrors();

    $kyc = OrganizationKyc::query()->where('organization_id', $organization->getKey())->sole();

    expect($kyc->tax_number)->toBe('1234567890')
        ->and($kyc->registration_number)->toBe('TR-99887')
        ->and($kyc->authorized_person_name)->toBe('Ayşe Yılmaz')
        ->and($kyc->authorized_person_national_id)->toBe('12345678901')
        ->and($kyc->meta('mersis'))->toBe('0123456789012345')
        // Stamped so an admin knows the company has presented itself for review.
        ->and($kyc->submitted_at)->not->toBeNull();
});

it('stores the national id encrypted, not in plaintext', function (): void {
    $seller = $this->actingAsSeller();
    $organization = ownedOrganization($seller);

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->callAction('submitKyc', [
            'tax_number' => '1234567890',
            'authorized_person_national_id' => '12345678901',
        ])
        ->assertHasNoActionErrors();

    // Read around the cast: the column must not hold the identifier itself.
    $raw = DB::table('organization_kyc')
        ->where('organization_id', $organization->getKey())
        ->value('authorized_person_national_id');

    expect($raw)->not->toBe('12345678901')
        ->and($raw)->not->toBeNull();
});

it('keeps the national id on file when the field is left blank', function (): void {
    $seller = $this->actingAsSeller();
    $organization = ownedOrganization($seller);

    $page = Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()]);

    $page->callAction('submitKyc', [
        'tax_number' => '1111111111',
        'authorized_person_national_id' => '12345678901',
    ])->assertHasNoActionErrors();

    // A correction to an unrelated field must not silently erase the id — the
    // form cannot re-send it, because it is never rendered.
    $page->callAction('submitKyc', [
        'tax_number' => '2222222222',
        'authorized_person_national_id' => null,
    ])->assertHasNoActionErrors();

    $kyc = OrganizationKyc::query()->where('organization_id', $organization->getKey())->sole();

    expect($kyc->tax_number)->toBe('2222222222')
        ->and($kyc->authorized_person_national_id)->toBe('12345678901');
});

it('upserts rather than creating a second record per organization', function (): void {
    $seller = $this->actingAsSeller();
    $organization = ownedOrganization($seller);

    $page = Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()]);
    $page->callAction('submitKyc', ['tax_number' => '1111111111']);
    $page->callAction('submitKyc', ['tax_number' => '2222222222']);

    expect(OrganizationKyc::query()->where('organization_id', $organization->getKey())->count())->toBe(1);
});

it('never renders the stored national id back into the form', function (): void {
    $seller = $this->actingAsSeller();
    $organization = ownedOrganization($seller);
    OrganizationKyc::factory()->for($organization)->create([
        'authorized_person_national_id' => '12345678901',
    ]);

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->mountAction('submitKyc')
        ->assertActionDataSet(['authorized_person_national_id' => null])
        ->assertDontSee('12345678901');
});

it('hides the action from a member without the manage-kyc capability', function (): void {
    $viewer = $this->actingAsSeller();
    $organization = Organization::factory()->create();
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewer->getKey()]);

    expect($viewer->can('manageKyc', $organization))->toBeFalse();

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->assertActionHidden('submitKyc');
});

it('denies a seller from another organization', function (): void {
    $this->actingAsSeller();
    $theirs = Organization::factory()->create();

    // Outside getEloquentQuery(): the page cannot resolve the record at all.
    expect(fn () => Livewire::test(ViewOrganization::class, ['record' => $theirs->getRouteKey()]))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(OrganizationKyc::query()->where('organization_id', $theirs->getKey())->exists())->toBeFalse();
});
