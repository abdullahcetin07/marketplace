<?php

declare(strict_types=1);

use App\Core\Domain\DataTransferObjects\BaseDTO;

/*
| BaseDTO hydration. These behaviours are what let controllers hand a typed
| object to a service instead of a raw array.
*/

/*
| A LOCAL fixture enum, deliberately not a production one. This file tests
| BaseDTO's scalar<->backed-enum casting, not any domain vocabulary; the previous
| coupling to App\Shared\Enums\Country broke the moment Country was promoted to a
| Localization lookup table (CLAUDE.md "enum or lookup table"). A fixture cannot
| break that way again, and a Unit test must not reach for a database-backed model.
*/
enum FixtureCountry: string
{
    case TR = 'TR';
    case DE = 'DE';
}

final class FixtureAddressData extends BaseDTO
{
    public function __construct(
        public readonly string $city,
        public readonly FixtureCountry $country,
    ) {}
}

final class FixtureStoreData extends BaseDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?FixtureAddressData $address = null,
        public readonly ?string $taxNumber = null,
    ) {}
}

it('hydrates from a camelCase array', function (): void {
    $dto = FixtureStoreData::fromArray(['name' => 'Acme', 'taxNumber' => '123']);

    expect($dto->name)->toBe('Acme')
        ->and($dto->taxNumber)->toBe('123');
});

it('hydrates from a snake_case array', function (): void {
    // HTTP payloads arrive snake_cased; mapping them by hand in every
    // controller is the boilerplate this exists to remove.
    $dto = FixtureStoreData::fromArray(['name' => 'Acme', 'tax_number' => '456']);

    expect($dto->taxNumber)->toBe('456');
});

it('falls back to declared defaults for absent keys', function (): void {
    $dto = FixtureStoreData::fromArray(['name' => 'Acme']);

    expect($dto->taxNumber)->toBeNull()
        ->and($dto->address)->toBeNull();
});

it('casts a scalar into a backed enum', function (): void {
    $dto = FixtureAddressData::fromArray(['city' => 'İstanbul', 'country' => 'TR']);

    expect($dto->country)->toBe(FixtureCountry::TR);
});

it('casts a nested array into a nested DTO', function (): void {
    $dto = FixtureStoreData::fromArray([
        'name' => 'Acme',
        'address' => ['city' => 'İstanbul', 'country' => 'TR'],
    ]);

    expect($dto->address)->toBeInstanceOf(FixtureAddressData::class)
        ->and($dto->address->country)->toBe(FixtureCountry::TR);
});

it('serialises to a snake_case array with enums unwrapped', function (): void {
    $dto = FixtureStoreData::fromArray([
        'name' => 'Acme',
        'taxNumber' => '123',
        'address' => ['city' => 'İstanbul', 'country' => 'TR'],
    ]);

    expect($dto->toArray())->toBe([
        'name' => 'Acme',
        'address' => ['city' => 'İstanbul', 'country' => 'TR'],
        'tax_number' => '123',
    ]);
});

it('strips nulls for partial updates', function (): void {
    $dto = FixtureStoreData::fromArray(['name' => 'Acme']);

    expect($dto->toFilledArray())->toBe(['name' => 'Acme']);
});

it('derives a copy without mutating the original', function (): void {
    $original = FixtureStoreData::fromArray(['name' => 'Acme']);
    $renamed = $original->with(['name' => 'Beta']);

    expect($original->name)->toBe('Acme')
        ->and($renamed->name)->toBe('Beta')
        ->and($renamed)->not->toBe($original);
});

it('encodes non-ASCII characters without escaping', function (): void {
    $dto = FixtureAddressData::fromArray(['city' => 'İstanbul', 'country' => 'TR']);

    expect($dto->toJson())->toContain('İstanbul');
});
