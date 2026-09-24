<?php

declare(strict_types=1);

use App\Shared\Rules\TurkishPhone;

/*
|--------------------------------------------------------------------------
| A phone number a courier can actually ring
|--------------------------------------------------------------------------
|
| The only rule on a delivery address was `max:32`, so `534-320-588` — nine
| digits, the last one missing — was accepted, stored and frozen onto an order.
| Two of 144 production addresses were exactly that on 2026-09-24, one of them on
| a live order waiting for a carrier.
|
| The form mask is why nobody noticed: it groups AAA-BBB-CCCC as you type, and
| nine digits come out looking like a finished number.
|
*/

it('refuses the numbers that actually reached production', function (string $value): void {
    expect(TurkishPhone::isValid($value))->toBeFalse();
})->with([
    'nine digits, masked to look whole' => '534-320-588',
    'the one on a live order' => '541-948-368',
]);

it('accepts the same number however somebody writes it', function (string $value): void {
    // Four spellings of one number, none of them a mistake worth refusing.
    expect(TurkishPhone::normalise($value))->toBe('5321234567');
})->with([
    'bare' => '5321234567',
    'trunk zero' => '0532 123 45 67',
    'country code' => '+90 532 123 45 67',
    'country code, no plus' => '90 532 123 45 67',
    'punctuated' => '(0532) 123-45-67',
]);

it('takes a landline too, because somebody answers it', function (): void {
    // A courier wants a mobile and most people give one; refusing a real number
    // to enforce a preference is rejecting valid input.
    expect(TurkishPhone::normalise('212 123 45 67'))->toBe('2121234567');
});

it('refuses what is not a subscriber number at all', function (string $value): void {
    expect(TurkishPhone::isValid($value))->toBeFalse();
})->with([
    'too short' => '532123456',
    'too long' => '53212345678',
    'starts with 1' => '1321234567',
    'starts with 9' => '9321234567',
    'letters' => 'telefonum yok',
    'empty' => '',
    // A string of zeroes survives the trunk-zero strip as nothing at all.
    'all zeroes' => '0000000000',
]);
