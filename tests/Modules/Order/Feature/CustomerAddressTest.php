<?php

declare(strict_types=1);

use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\DeleteCustomerAddressAction;
use App\Modules\Order\Application\Actions\SetDefaultAddressAction;
use App\Modules\Order\Application\Actions\UpdateCustomerAddressAction;
use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\CustomerAddress;

/*
|--------------------------------------------------------------------------
| The address book (ADR-056)
|--------------------------------------------------------------------------
|
| THE THING THAT MAKES THIS TABLE COMFORTABLE TO EDIT is that no order points at
| it. A placed order holds its own frozen snapshot (ADR-053/056), so a customer
| who moves house changes where their NEXT parcel goes and nothing about where the
| last one went. If an order referenced these rows, none of the actions in this
| file could exist in the shape they do.
|
| The second theme is that A CUSTOMER ALWAYS HAS A DEFAULT once they have any
| address at all. "None" is a state that only ever produces an extra question at
| checkout, so the first address becomes both defaults, promotion clears the
| previous one, and deleting a default promotes whatever is left.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * The ordinary payload, with only what a test cares about overridden.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @param  array<string, mixed>  $overrides
 */
function addressPayload(array $overrides = []): CustomerAddressDTO
{
    return new CustomerAddressDTO(...array_merge([
        'label' => 'Ev',
        'recipientName' => 'Ayşe Yılmaz',
        'phone' => '+905551234567',
        'line1' => 'Bağdat Caddesi 120',
        'city' => 'İstanbul',
        'countryCode' => 'TR',
        'district' => 'Kadıköy',
        'postalCode' => '34710',
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('resolves the country from an ISO code, never an id', function (): void {
    $address = app(CreateCustomerAddressAction::class)->run(1, 'musteri', addressPayload());

    // Internal ids never cross a boundary (non-negotiable #7); the DTO speaks
    // ISO codes and the action resolves Localization's row.
    expect($address->country->iso2)->toBe('TR')
        ->and($address->city)->toBe('İstanbul')
        ->and($address->district)->toBe('Kadıköy');
});

it('refuses a country that does not exist', function (): void {
    expect(fn () => app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload(['countryCode' => 'ZZ'])))
        ->toThrow(OrderException::class);
});

it('makes the first address both defaults, whatever the payload says', function (): void {
    $address = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());

    /*
     * A customer with exactly one address and no defaults would be asked to choose
     * between one option at checkout — a question with no information in it — and
     * every client would have to special-case the empty book.
     */
    expect($address->is_default_shipping)->toBeTrue()
        ->and($address->is_default_billing)->toBeTrue();
});

it('leaves a second address undefaulted unless asked', function (): void {
    app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());
    $second = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload(['label' => 'İş']));

    expect($second->is_default_shipping)->toBeFalse()
        ->and($second->is_default_billing)->toBeFalse();
});

it('clears the previous default when a new address claims it', function (): void {
    $first = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());

    $second = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload([
        'label' => 'İş',
        'isDefaultShipping' => true,
    ]));

    /*
     * The application half of "one default per purpose". The partial unique index
     * behind it exists only on PostgreSQL (SQLite cannot express it over a
     * boolean), so this is the half that holds on every engine.
     */
    expect($first->fresh()->is_default_shipping)->toBeFalse()
        ->and($second->is_default_shipping)->toBeTrue()
        // And billing is untouched — the two defaults move independently, which
        // is the entire point of having two.
        ->and($first->fresh()->is_default_billing)->toBeTrue();
});

it('keeps one customer’s book out of another’s', function (): void {
    app(CreateCustomerAddressAction::class)->run(1, 'a', addressPayload());
    app(CreateCustomerAddressAction::class)->run(2, 'b', addressPayload(['label' => 'İş']));

    $addresses = app(CustomerAddressRepositoryContract::class);

    expect($addresses->forCustomer(1))->toHaveCount(1)
        ->and($addresses->forCustomer(2))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Editing — safe here, and only here
|--------------------------------------------------------------------------
*/

it('lets a customer move house without rewriting where old parcels went', function (): void {
    $address = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());

    /*
     * THE PROPERTY THE WHOLE SEPARATION EXISTS FOR (ADR-053/056). An order holds
     * its own snapshot, so editing this row is a change to the FUTURE only. If an
     * order referenced it, this action could not exist.
     */
    app(UpdateCustomerAddressAction::class)->run($address, addressPayload([
        'line1' => 'Atatürk Bulvarı 5',
        'city' => 'Ankara',
        'district' => 'Çankaya',
    ]));

    expect($address->fresh()->line1)->toBe('Atatürk Bulvarı 5')
        ->and($address->fresh()->city)->toBe('Ankara');
});

it('will not demote a default down to nothing', function (): void {
    $address = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());

    // Passing false on the current default would leave the customer with NO
    // default and a checkout with nothing to preselect. A default moves by
    // promoting another address.
    app(UpdateCustomerAddressAction::class)->run($address, addressPayload([
        'isDefaultShipping' => false,
        'isDefaultBilling' => false,
    ]));

    expect($address->fresh()->is_default_shipping)->toBeTrue()
        ->and($address->fresh()->is_default_billing)->toBeTrue();
});

it('promotes on edit, clearing the previous holder', function (): void {
    $first = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());
    $second = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload(['label' => 'İş']));

    app(UpdateCustomerAddressAction::class)->run($second, addressPayload([
        'label' => 'İş',
        'isDefaultBilling' => true,
    ]));

    expect($second->fresh()->is_default_billing)->toBeTrue()
        ->and($first->fresh()->is_default_billing)->toBeFalse()
        // Shipping did not move — only what was asked for.
        ->and($first->fresh()->is_default_shipping)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Promoting and deleting
|--------------------------------------------------------------------------
*/

it('promotes one address for one purpose at a time', function (): void {
    $home = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());
    $work = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload(['label' => 'İş']));

    // "Deliver to the office, invoice the home address" — the ordinary case
    // ADR-056 exists for.
    app(SetDefaultAddressAction::class)->run($work, true, false);

    expect($work->fresh()->is_default_shipping)->toBeTrue()
        ->and($work->fresh()->is_default_billing)->toBeFalse()
        ->and($home->fresh()->is_default_shipping)->toBeFalse()
        ->and($home->fresh()->is_default_billing)->toBeTrue();
});

it('soft deletes so an open page does not 404 under the customer', function (): void {
    $first = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());
    app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload(['label' => 'İş']));

    app(DeleteCustomerAddressAction::class)->run($first);

    expect(CustomerAddress::query()->withTrashed()->whereKey($first->getKey())->exists())->toBeTrue()
        ->and(app(CustomerAddressRepositoryContract::class)->forCustomer(1))->toHaveCount(1);
});

it('moves the default on when the default is deleted', function (): void {
    $first = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());
    $second = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload(['label' => 'İş']));

    app(DeleteCustomerAddressAction::class)->run($first);

    /*
     * An arbitrary choice, and a strictly better one than a checkout with nothing
     * preselected — the customer can change it in one click.
     */
    expect($second->fresh()->is_default_shipping)->toBeTrue()
        ->and($second->fresh()->is_default_billing)->toBeTrue();
});

it('accepts an empty book when the last address goes', function (): void {
    $only = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());

    app(DeleteCustomerAddressAction::class)->run($only);

    // Nothing to promote, and an empty book is the honest state.
    expect(app(CustomerAddressRepositoryContract::class)->forCustomer(1))->toHaveCount(0)
        ->and(app(CustomerAddressRepositoryContract::class)->defaultShippingFor(1))->toBeNull();
});

it('deleting a non-default leaves the defaults where they are', function (): void {
    $home = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());
    $work = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload(['label' => 'İş']));

    app(DeleteCustomerAddressAction::class)->run($work);

    expect($home->fresh()->is_default_shipping)->toBeTrue()
        ->and($home->fresh()->is_default_billing)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The snapshot an order will freeze (ADR-053/056)
|--------------------------------------------------------------------------
*/

it('freezes the country as a name and a code, never as an id', function (): void {
    $address = app(CreateCustomerAddressAction::class)->run(1, 'm', addressPayload());

    $snapshot = $address->toSnapshot();

    /*
     * A snapshot containing a foreign key is not a snapshot: this array must still
     * make sense years later if a country row is renamed or deactivated.
     */
    expect($snapshot)->not->toHaveKey('country_id')
        ->and($snapshot['country_code'])->toBe('TR')
        ->and($snapshot['country_name'])->toBeString()
        ->and($snapshot['recipient_name'])->toBe('Ayşe Yılmaz')
        ->and($snapshot['city'])->toBe('İstanbul');
});
