<?php

declare(strict_types=1);

use App\Filament\Seller\Auth\Register;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller self-registration (/seller/register)
|--------------------------------------------------------------------------
|
| Filament's stock Register page posts a single `name` field and mass-assigns
| it. `users.name` does not exist here — it was split into first_name/last_name
| (ADR-012) and neither `name` nor the computed `display_name` is fillable, so
| the stock page threw MassAssignmentException on every signup. These tests pin
| the replacement: the form this platform's model can actually be created from.
|
| The panel is set explicitly because a Livewire test has no panel middleware to
| do it — the page reads filament() while building its form.
|
| @see App\Filament\Seller\Auth\Register
*/

beforeEach(function (): void {
    // Registration resolves the default language and currency (throw if
    // Localization is unseeded) and assigns the base Seller role, which must
    // exist for the seller guard — so seed roles and permissions too.
    $this->seedPlatform();
    $this->seedRolesAndPermissions();

    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

it('is the page the seller panel actually serves at /seller/register', function (): void {
    // The provider wiring, not just the class: `->registration()` with no
    // argument silently falls back to Filament's stock page, which is the bug
    // this replaces.
    $this->get(route('filament.seller.auth.register'))
        ->assertSuccessful()
        ->assertSeeLivewire(Register::class);
});

it('renders the page with our fields and none of Filament’s', function (): void {
    Livewire::test(Register::class)
        ->assertSuccessful()
        ->assertFormFieldExists('first_name')
        ->assertFormFieldExists('last_name')
        ->assertFormFieldExists('email')
        ->assertFormFieldExists('password')
        // The field that caused the MassAssignmentException. Its absence is the
        // regression guard — re-inheriting the stock schema brings it back.
        ->assertFormFieldDoesNotExist('name');
});

it('registers a seller', function (): void {
    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => 'Ayşe',
            'last_name' => 'Yılmaz',
            'email' => 'ayse@example.test',
            'password' => 'sifre-cok-guclu',
            'passwordConfirmation' => 'sifre-cok-guclu',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $seller = Seller::query()->where('email', 'ayse@example.test')->sole();

    expect($seller->type)->toBe(UserType::Seller)
        ->and($seller->first_name)->toBe('Ayşe')
        ->and($seller->last_name)->toBe('Yılmaz')
        ->and($seller->display_name)->toBe('Ayşe Yılmaz')
        ->and($seller->status)->toBe(Status::Active)
        // Stored hashed by the model's `hashed` cast, not by the form.
        ->and($seller->password)->not->toBe('sifre-cok-guclu')
        ->and(Hash::check('sifre-cok-guclu', $seller->password))->toBeTrue()
        // The panel's ->emailVerification() owns this, not registration.
        ->and($seller->email_verified_at)->toBeNull()
        // Mirrors CreateAdminCommand: a concrete, editable preference set.
        ->and($seller->language_id)->not->toBeNull()
        ->and($seller->currency_id)->not->toBeNull()
        // Given the base Seller role on signup, so the panel is usable — an
        // account with no role could sign in and then do nothing.
        ->and($seller->hasRole(config('marketplace.roles.seller')))->toBeTrue();
});

it('signs the new seller in on the seller guard and no other', function (): void {
    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => 'Ayşe',
            'email' => 'ayse@example.test',
            'password' => 'sifre-cok-guclu',
            'passwordConfirmation' => 'sifre-cok-guclu',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(auth('seller')->check())->toBeTrue()
        ->and(auth('admin')->check())->toBeFalse()
        ->and(auth('customer')->check())->toBeFalse();
});

it('stores a missing surname as null rather than an empty string', function (): void {
    // ADR-012: last_name is nullable on purpose — sole traders and single-name
    // cultures must be representable.
    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => 'Madonna',
            'last_name' => '',
            'email' => 'madonna@example.test',
            'password' => 'sifre-cok-guclu',
            'passwordConfirmation' => 'sifre-cok-guclu',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $seller = Seller::query()->where('email', 'madonna@example.test')->sole();

    expect($seller->last_name)->toBeNull()
        ->and($seller->display_name)->toBe('Madonna');
});

it('refuses an address already registered as a seller', function (): void {
    Seller::factory()->create(['email' => 'taken@example.test']);

    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => 'Ayşe',
            'email' => 'taken@example.test',
            'password' => 'sifre-cok-guclu',
            'passwordConfirmation' => 'sifre-cok-guclu',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);

    expect(Seller::query()->where('email', 'taken@example.test')->count())->toBe(1);
});

it('accepts an address that exists as a customer or an admin', function (): void {
    // Uniqueness is scoped to (type, email). One human is routinely both a
    // shopper and a merchant; a shared inbox is routinely both.
    Customer::factory()->create(['email' => 'ikisi@example.test']);
    Admin::factory()->create(['email' => 'ikisi@example.test']);

    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => 'Ayşe',
            'email' => 'ikisi@example.test',
            'password' => 'sifre-cok-guclu',
            'passwordConfirmation' => 'sifre-cok-guclu',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Seller::query()->where('email', 'ikisi@example.test')->exists())->toBeTrue();
});

it('requires a first name and a matching password confirmation', function (): void {
    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => '',
            'email' => 'ayse@example.test',
            'password' => 'sifre-cok-guclu',
            'passwordConfirmation' => 'baska-bir-sifre',
        ])
        ->call('register')
        ->assertHasFormErrors(['first_name', 'password']);

    expect(Seller::query()->where('email', 'ayse@example.test')->exists())->toBeFalse();
});
