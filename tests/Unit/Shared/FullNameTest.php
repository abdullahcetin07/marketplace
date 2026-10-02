<?php

declare(strict_types=1);

use App\Shared\Rules\FullName;

/*
|--------------------------------------------------------------------------
| A name a courier can hand a parcel to
|--------------------------------------------------------------------------
|
| The field was labelled "Alıcı adı" — recipient's NAME — with
| `required|string|max:255` behind it, so a shopper who typed "Taha" had answered
| correctly. Six of twenty-six paid orders carried a single word, two of them
| waiting to be handed to a carrier when this was found (2026-10-02).
|
| THIS IS NOT ABOUT USER NAMES. ADR-012 makes `users.last_name` nullable on
| purpose, for sole traders and single-name cultures, and that stands. A cargo
| label is a different question.
|
*/

it('refuses the names that are already on live orders', function (string $value): void {
    expect(FullName::isValid($value))->toBeFalse();
})->with([
    'awaiting a carrier' => 'Taha',
    'awaiting a carrier too' => 'Enes',
    'already delivered' => 'Melek',
    'padded' => '  Zeynep  ',
    'empty' => '',
]);

it('refuses an initial, which is not a surname a courier can use', function (string $value): void {
    expect(FullName::isValid($value))->toBeFalse();
})->with([
    'with a dot' => 'Ahmet Y.',
    'without' => 'Ahmet Y',
    'both initials' => 'A B',
]);

it('accepts a real name however it is spelled', function (string $value): void {
    expect(FullName::isValid($value))->toBeTrue();
})->with([
    'plain' => 'Ayşe Yılmaz',
    // Turkish letters are two bytes and one letter; the count is unicode-aware.
    'all caps with Turkish letters' => 'SALİM KOÇ',
    // Anything beyond the first two parts is none of this rule's business.
    'middle name' => 'Zeynep Şahin Öz',
    'compound given name' => 'Mehmet-Ali Can',
    'double space' => 'Ayşe  Yılmaz',
]);
