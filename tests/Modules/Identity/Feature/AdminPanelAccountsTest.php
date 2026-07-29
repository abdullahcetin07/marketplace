<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Models\User;
use App\Modules\Identity\Domain\Models\LoginAttempt;
use App\Modules\Identity\Presentation\Filament\Resources\CustomerResource;
use App\Modules\Identity\Presentation\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Modules\Identity\Presentation\Filament\Resources\RelationManagers\LoginHistoryRelationManager;
use App\Modules\Identity\Presentation\Filament\Resources\SellerResource;
use App\Modules\Identity\Presentation\Filament\Resources\SellerResource\Pages\ListSellers;
use App\Modules\Identity\Presentation\Filament\Resources\SellerResource\Pages\ViewSeller;
use App\Modules\Identity\Presentation\Filament\Resources\StaffResource;
use App\Modules\Identity\Presentation\Filament\Resources\StaffResource\Pages\CreateStaff;
use App\Modules\Identity\Presentation\Filament\Resources\StaffResource\Pages\EditStaff;
use App\Modules\Identity\Presentation\Filament\Resources\StaffResource\Pages\ListStaff;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Admin panel — accounts, split by actor type
|--------------------------------------------------------------------------
|
| The single all-users list became three areas: Personel (my team), Satıcılar
| and Müşteriler (oversight only). What is worth pinning here is not the layout
| but the boundaries the split exists to draw:
|
|  1. Each area shows EXACTLY one actor type. A seller appearing under Personel
|     is not a cosmetic bug — it is a staff-role surface pointed at a merchant.
|  2. Staff creation obeys the privilege-escalation guard: an Admin may
|     provision up to their own level, never Super Admin, and hiding the option
|     is not what enforces it.
|  3. The oversight areas expose NO role assignment and NO team management, and
|     cannot create an account at all.
|
| The panel is set explicitly because a Livewire test has no panel middleware to
| do it.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * An administrator holding the platform Admin role — broad access, but NOT the
 * super-admin bypass. That distinction is the whole subject of the escalation
 * tests below.
 */
function asPlatformAdmin(Admin $admin): Admin
{
    $admin->syncRoles([config('marketplace.roles.admin')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    return $admin;
}

function asSuperAdmin(Admin $admin): Admin
{
    $admin->syncRoles([config('marketplace.roles.super_admin')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    return $admin;
}

/**
 * One account of every type, so a listing that leaks another type has something
 * to leak.
 *
 * The factories are declared on the base User, so the shape is stated as User —
 * what each row IS is proved by the assertions below, not by a docblock.
 *
 * @return array{seller: User, customer: User, staff: User}
 */
function oneOfEachActorType(): array
{
    return [
        'seller' => Seller::factory()->create(['first_name' => 'Satıcı', 'email' => 'satici@example.test']),
        'customer' => Customer::factory()->create(['first_name' => 'Müşteri', 'email' => 'musteri@example.test']),
        'staff' => Admin::factory()->create(['first_name' => 'Personel', 'email' => 'personel@example.test']),
    ];
}

/*
|--------------------------------------------------------------------------
| 1. Each area shows exactly one actor type
|--------------------------------------------------------------------------
*/

it('lists staff only under Personel — no seller, no customer', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $accounts = oneOfEachActorType();

    Livewire::test(ListStaff::class)
        ->assertCanSeeTableRecords([$accounts['staff']])
        ->assertCanNotSeeTableRecords([$accounts['seller'], $accounts['customer']]);
});

it('lists sellers only under Satıcılar', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $accounts = oneOfEachActorType();

    Livewire::test(ListSellers::class)
        ->assertCanSeeTableRecords([$accounts['seller']])
        ->assertCanNotSeeTableRecords([$accounts['staff'], $accounts['customer']]);
});

it('lists customers only under Müşteriler', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $accounts = oneOfEachActorType();

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$accounts['customer']])
        ->assertCanNotSeeTableRecords([$accounts['staff'], $accounts['seller']]);
});

it('scopes every area query to its own actor type', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    oneOfEachActorType();

    expect(StaffResource::getEloquentQuery()->pluck('type')->unique()->all())->toBe([UserType::Admin])
        ->and(SellerResource::getEloquentQuery()->pluck('type')->unique()->all())->toBe([UserType::Seller])
        ->and(CustomerResource::getEloquentQuery()->pluck('type')->unique()->all())->toBe([UserType::Customer]);
});

/*
|--------------------------------------------------------------------------
| 2. Staff creation and the escalation guard
|--------------------------------------------------------------------------
*/

it('creates a staff account with a staff role, mirroring the CLI command', function (): void {
    asSuperAdmin($this->actingAsAdmin());

    Livewire::test(CreateStaff::class)
        ->fillForm([
            'first_name' => 'Yeni',
            'last_name' => 'Personel',
            'email' => 'yeni.personel@example.test',
            'password' => 'CokGucluBirParola1!',
            'password_confirmation' => 'CokGucluBirParola1!',
            'roles' => [config('marketplace.roles.category_manager')],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Admin::query()->where('email', 'yeni.personel@example.test')->sole();

    expect($created->type)->toBe(UserType::Admin)
        ->and($created->status)->toBe(Status::Active)
        // The same locale resolution and verified stamp the CLI applies, so a
        // panel-created operator and a CLI-created one are the same account.
        ->and($created->language_id)->not->toBeNull()
        ->and($created->currency_id)->not->toBeNull()
        ->and($created->email_verified_at)->not->toBeNull()
        ->and($created->roles()->pluck('name')->all())->toBe([config('marketplace.roles.category_manager')]);
});

it('does not offer Super Admin to an Admin, and does offer it to a Super Admin', function (): void {
    $admin = asPlatformAdmin($this->actingAsAdmin());

    expect(StaffResource::staffRoleOptions())
        ->not->toHaveKey(config('marketplace.roles.super_admin'))
        ->toHaveKey(config('marketplace.roles.admin'));

    asSuperAdmin($admin);

    expect(StaffResource::staffRoleOptions())
        ->toHaveKey(config('marketplace.roles.super_admin'));
});

it('refuses a forged Super Admin grant from an Admin — the hidden option is not the control', function (): void {
    asPlatformAdmin($this->actingAsAdmin());

    expect(fn () => StaffResource::assertRolesGrantable([config('marketplace.roles.super_admin')]))
        ->toThrow(AuthorizationException::class);

    // And nothing was created by the attempt.
    expect(Admin::query()->where('email', 'kacak@example.test')->exists())->toBeFalse();
});

it('lets a Super Admin grant Super Admin', function (): void {
    asSuperAdmin($this->actingAsAdmin());

    Livewire::test(CreateStaff::class)
        ->fillForm([
            'first_name' => 'İkinci',
            'email' => 'ikinci.super@example.test',
            'password' => 'CokGucluBirParola1!',
            'password_confirmation' => 'CokGucluBirParola1!',
            'roles' => [config('marketplace.roles.super_admin')],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Admin::query()->where('email', 'ikinci.super@example.test')->sole();

    expect($created->isSuperAdmin())->toBeTrue();
});

it('changes staff roles through the edit page', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $colleague = Admin::factory()->create();
    $colleague->syncRoles([config('marketplace.roles.support')]);

    Livewire::test(EditStaff::class, ['record' => $colleague->getRouteKey()])
        ->fillForm([
            'roles' => [config('marketplace.roles.finance')],
            'reason' => 'Ekip değişikliği',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($colleague->fresh()->roles()->pluck('name')->all())
        ->toBe([config('marketplace.roles.finance')]);
});

it('suspends and reinstates a staff account through the action', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $colleague = Admin::factory()->create(['status' => Status::Active]);

    Livewire::test(EditStaff::class, ['record' => $colleague->getRouteKey()])
        ->fillForm(['status' => Status::Suspended->value, 'reason' => 'Ayrıldı'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($colleague->fresh()->status)->toBe(Status::Suspended);
});

it('refuses an Admin editing a Super Admin — the existing escalation guard', function (): void {
    asPlatformAdmin($this->actingAsAdmin());

    $owner = Admin::factory()->create();
    $owner->syncRoles([config('marketplace.roles.super_admin')]);
    $owner->refresh();

    expect(StaffResource::canEdit($owner))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 3. The oversight areas grant nothing
|--------------------------------------------------------------------------
*/

it('exposes no role assignment and no team management on the oversight areas', function (): void {
    foreach ([SellerResource::class, CustomerResource::class] as $resource) {
        // No create page and no edit page — only a list and a read-only detail.
        expect(array_keys($resource::getPages()))->toBe(['index', 'view'])
            ->and($resource::canCreate())->toBeFalse()
            // The ONLY relation these areas carry is the forensic timeline.
            // A members/team manager appearing here is the thing this asserts
            // against: a seller's team is not an operator's to manage.
            ->and($resource::getRelations())->toBe([LoginHistoryRelationManager::class]);
    }

    // Staff, by contrast, IS provisioned here — the contrast is the point.
    expect(array_keys(StaffResource::getPages()))->toContain('create');
});

it('offers no role or team action on a seller or customer row', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    Seller::factory()->create();
    Customer::factory()->create();

    Livewire::test(ListSellers::class)
        ->assertTableActionDoesNotExist('changeRole')
        ->assertTableActionDoesNotExist('assignRoles')
        ->assertTableActionDoesNotExist('invite')
        // Suspend and reinstate are what an oversight area DOES offer.
        ->assertTableActionExists('suspend');

    Livewire::test(ListCustomers::class)
        ->assertTableActionDoesNotExist('changeRole')
        ->assertTableActionDoesNotExist('assignRoles')
        ->assertTableActionDoesNotExist('invite');
});

it('never lets an oversight area edit or delete a record', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $seller = Seller::factory()->create();
    $customer = Customer::factory()->create();

    expect(SellerResource::canEdit($seller))->toBeFalse()
        ->and(SellerResource::canDelete($seller))->toBeFalse()
        ->and(CustomerResource::canEdit($customer))->toBeFalse()
        ->and(CustomerResource::canDelete($customer))->toBeFalse();
});

it('suspends a seller from the oversight area through the action', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $seller = Seller::factory()->create(['status' => Status::Active]);

    Livewire::test(ListSellers::class)
        ->callTableAction('suspend', $seller, ['reason' => 'Sahte ürün ilanı']);

    expect($seller->fresh()->status)->toBe(Status::Suspended);
});

it('reinstates a suspended customer from the oversight area', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $customer = Customer::factory()->create(['status' => Status::Suspended]);

    Livewire::test(ListCustomers::class)
        ->callTableAction('reinstate', $customer, ['reason' => 'Şikayet asılsız']);

    expect($customer->fresh()->status)->toBe(Status::Active);
});

/*
|--------------------------------------------------------------------------
| 4. Who may open which area
|--------------------------------------------------------------------------
*/

it('gates the staff area on user.manage_staff and the oversight areas on their own abilities', function (): void {
    // Support: the helpdesk. Oversees merchants and shoppers; does not hire.
    $support = $this->actingAsAdmin();
    $support->syncRoles([config('marketplace.roles.support')]);
    $support->refresh()->loadMissing('roles.permissions', 'permissions');

    expect(StaffResource::canViewAny())->toBeFalse()
        ->and(SellerResource::canViewAny())->toBeTrue()
        ->and(CustomerResource::canViewAny())->toBeTrue();

    // Admin holds all three.
    asPlatformAdmin($support);

    expect(StaffResource::canViewAny())->toBeTrue()
        ->and(SellerResource::canViewAny())->toBeTrue()
        ->and(CustomerResource::canViewAny())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 5. The forensic timeline
|--------------------------------------------------------------------------
*/

it('shows an account its login history, failures included', function (): void {
    asSuperAdmin($this->actingAsAdmin());
    $seller = Seller::factory()->create();

    $success = LoginAttempt::factory()->create([
        'user_id' => $seller->getKey(),
        'email' => $seller->email,
        'guard' => UserType::Seller->guard(),
        'successful' => true,
    ]);

    $failure = LoginAttempt::factory()->create([
        'user_id' => $seller->getKey(),
        'email' => $seller->email,
        'guard' => UserType::Seller->guard(),
        'successful' => false,
    ]);

    Livewire::test(LoginHistoryRelationManager::class, [
        'ownerRecord' => $seller,
        'pageClass' => ViewSeller::class,
    ])->assertCanSeeTableRecords([$success, $failure]);
});
