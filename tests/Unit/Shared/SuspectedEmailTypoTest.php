<?php

declare(strict_types=1);

use App\Shared\Rules\SuspectedEmailTypo;

/*
|--------------------------------------------------------------------------
| The handful of domains nobody ever means to type
|--------------------------------------------------------------------------
|
| A customer registered as `…@gmail.com.tr`, paid for an order and heard nothing
| since: no confirmation, no shipping notice, no password reset. Thirteen
| customers have bought without ever verifying an address, so the verification
| link this platform relies on catches none of them.
|
| The list is curated rather than computed, and the two halves of that decision
| are both pinned below: the domains that must be caught, and the real regional
| ones that must not.
|
*/

it('names what they meant', function (string $typed, string $meant): void {
    expect(SuspectedEmailTypo::suggest($typed))->toBe($meant);
})->with([
    // The one that actually happened.
    'the live case' => ['yelizaveta@gmail.com.tr', 'yelizaveta@gmail.com'],

    /*
     * THE DANGEROUS HALF. These hold live MX records — squatted, accepting
     * mail — so a confirmation sent there hands a stranger the customer's name,
     * address and phone. A DNS check waves every one of them through.
     */
    'squatted, resolves' => ['ayse@gmai.com', 'ayse@gmail.com'],
    'squatted, transposed' => ['ayse@gamil.com', 'ayse@gmail.com'],
    'wrong tld, resolves' => ['ayse@gmail.co', 'ayse@gmail.com'],
    'icloud squat' => ['ayse@iclould.com', 'ayse@icloud.com'],

    'n for m' => ['mehmet@hotmail.con', 'mehmet@hotmail.com'],
    'transposed hotmail' => ['mehmet@hotmial.com', 'mehmet@hotmail.com'],
    'short yahoo' => ['ali@yaho.com', 'ali@yahoo.com'],
    'outlook' => ['ali@outlok.com', 'ali@outlook.com'],

    // Case and padding are the user's, not a different domain.
    'upper case' => ['Ayse@GMAIL.COM.TR', 'Ayse@gmail.com'],
]);

it('leaves alone the regional domains that really carry mail', function (string $email): void {
    /*
     * EACH ONE VERIFIED TO HOLD MX BEFORE BEING LEFT OUT. Edit distance would
     * flag all four; blocking a real provider is a worse failure than missing a
     * typo, because it turns away a customer who typed their address correctly.
     */
    expect(SuspectedEmailTypo::suggest($email))->toBeNull();
})->with([
    'Yahoo Turkey' => 'ayse@yahoo.com.tr',
    'Hotmail Turkey' => 'ayse@hotmail.com.tr',
    'Outlook Turkey' => 'ayse@outlook.com.tr',
    'Yandex Turkey' => 'ayse@yandex.com.tr',
    'the real thing' => 'ayse@gmail.com',
    'a company domain' => 'ayse@turuncukasa.com',
]);

it('says nothing about an address it cannot read', function (string $value): void {
    expect(SuspectedEmailTypo::suggest($value))->toBeNull();
})->with([
    'no at sign' => 'ayse.gmail.com.tr',
    'empty' => '',
    // The local part is the user's and is never rewritten — only the domain is
    // matched, and `@` inside a quoted local part must not confuse that.
    'at in local part' => '"a@b"@turuncukasa.com',
]);
