<?php

declare(strict_types=1);

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages\ViewOrganization;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — the payout account
|--------------------------------------------------------------------------
|
| A modal header action mirroring UpsertBankAccountRequest and delegating to
| UpsertBankAccountAction, which upserts the PRIMARY account. The IBAN is the
| point of care: encrypted at rest, audit-excluded, never rendered back into the
| form, and a change resets verification.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * A company the seller owns, with the owner membership that carries the
 * bank-account capabilities.
 */
function bankOrganization(App\Models\Seller $seller): Organization
{
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    return $organization;
}

it('sets the payout account through the action', function (): void {
    $seller = $this->actingAsSeller();
    $organization = bankOrganization($seller);
    $currency = Currency::query()->where('is_active', true)->firstOrFail();

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->callAction('bankAccount', [
            'account_holder' => 'Raftabul Ticaret A.Ş.',
            'iban' => 'TR33 0006 1005 1978 6457 8413 26',
            'bank_name' => 'Ziraat Bankası',
            'currency_code' => $currency->code,
        ])
        ->assertHasNoActionErrors();

    $account = OrganizationBankAccount::query()
        ->where('organization_id', $organization->getKey())
        ->sole();

    expect($account->account_holder)->toBe('Raftabul Ticaret A.Ş.')
        ->and($account->bank_name)->toBe('Ziraat Bankası')
        ->and($account->currency_id)->toBe($currency->getKey())
        ->and($account->is_primary)->toBeTrue()
        // Normalised by the DTO: unspaced and upper-cased.
        ->and($account->iban)->toBe('TR330006100519786457841326');
});

it('stores the iban encrypted, not in plaintext', function (): void {
    $seller = $this->actingAsSeller();
    $organization = bankOrganization($seller);

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->callAction('bankAccount', [
            'account_holder' => 'Raftabul',
            'iban' => 'TR330006100519786457841326',
            'currency_code' => Currency::query()->where('is_active', true)->value('code'),
        ])
        ->assertHasNoActionErrors();

    $raw = DB::table('organization_bank_accounts')
        ->where('organization_id', $organization->getKey())
        ->value('iban');

    expect($raw)->not->toBe('TR330006100519786457841326')
        ->and($raw)->not->toBeNull();
});

it('never renders the stored iban back into the form', function (): void {
    $seller = $this->actingAsSeller();
    $organization = bankOrganization($seller);
    OrganizationBankAccount::factory()->for($organization)->create([
        'is_primary' => true,
        'iban' => 'TR330006100519786457841326',
    ]);

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->mountAction('bankAccount')
        ->assertActionDataSet(['iban' => null])
        ->assertDontSee('TR330006100519786457841326');
});

it('shows only the masked iban on the company page', function (): void {
    $seller = $this->actingAsSeller();
    $organization = bankOrganization($seller);
    OrganizationBankAccount::factory()->for($organization)->create([
        'is_primary' => true,
        'iban' => 'TR330006100519786457841326',
    ]);

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('•••• 1326')
        ->assertDontSee('TR330006100519786457841326');
});

it('replaces the primary account rather than adding a second', function (): void {
    $seller = $this->actingAsSeller();
    $organization = bankOrganization($seller);
    $code = Currency::query()->where('is_active', true)->value('code');

    $page = Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()]);

    $page->callAction('bankAccount', [
        'account_holder' => 'İlk Hesap',
        'iban' => 'TR330006100519786457841326',
        'currency_code' => $code,
    ])->assertHasNoActionErrors();

    $page->callAction('bankAccount', [
        'account_holder' => 'İkinci Hesap',
        'iban' => 'TR120006100519786457841327',
        'currency_code' => $code,
    ])->assertHasNoActionErrors();

    $accounts = OrganizationBankAccount::query()
        ->where('organization_id', $organization->getKey())
        ->get();

    expect($accounts)->toHaveCount(1)
        ->and($accounts->first()->account_holder)->toBe('İkinci Hesap')
        ->and($accounts->first()->iban)->toBe('TR120006100519786457841327');
});

it('resets verification when the iban changes', function (): void {
    $seller = $this->actingAsSeller();
    $organization = bankOrganization($seller);
    $code = Currency::query()->where('is_active', true)->value('code');

    $account = OrganizationBankAccount::factory()->for($organization)->create([
        'is_primary' => true,
        'iban' => 'TR330006100519786457841326',
        'verified_at' => now(),
    ]);

    expect($account->verified_at)->not->toBeNull();

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->callAction('bankAccount', [
            'account_holder' => 'Raftabul',
            'iban' => 'TR120006100519786457841327',
            'currency_code' => $code,
        ])
        ->assertHasNoActionErrors();

    // A new number is an unverified number — payouts must not inherit trust.
    expect($account->fresh()->verified_at)->toBeNull();
});

it('rejects an iban that is too short', function (): void {
    $seller = $this->actingAsSeller();
    $organization = bankOrganization($seller);

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->callAction('bankAccount', [
            'account_holder' => 'Raftabul',
            'iban' => 'TR33',
            'currency_code' => Currency::query()->where('is_active', true)->value('code'),
        ])
        ->assertHasActionErrors(['iban']);

    expect(OrganizationBankAccount::query()->where('organization_id', $organization->getKey())->exists())
        ->toBeFalse();
});

it('hides the action from a member without the bank-account capability', function (): void {
    $viewer = $this->actingAsSeller();
    $organization = Organization::factory()->create();
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewer->getKey()]);

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->assertActionHidden('bankAccount');
});

it('denies a seller from another organization', function (): void {
    $this->actingAsSeller();
    $theirs = Organization::factory()->create();

    expect(fn () => Livewire::test(ViewOrganization::class, ['record' => $theirs->getRouteKey()]))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(OrganizationBankAccount::query()->where('organization_id', $theirs->getKey())->exists())
        ->toBeFalse();
});
