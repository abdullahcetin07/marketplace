<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| users:purge-test-accounts
|--------------------------------------------------------------------------
|
| The risk in this command runs one way: deleting somebody real. So the tests
| that matter are the KEEPS — a real domain, and a test-domain account that
| turns out to have history behind it.
|
*/

beforeEach(function (): void {
    config()->set('mail.blocked_recipient_domains', ['example.com', 'test.com']);
});

it('reports without writing anything by default', function (): void {
    $customer = Customer::factory()->create(['email' => 'tester@example.com']);

    $this->artisan('users:purge-test-accounts')
        ->expectsOutputToContain('tester@example.com')
        ->assertSuccessful();

    expect($customer->fresh()->deleted_at)->toBeNull();
});

it('soft-deletes a test account with nothing behind it', function (): void {
    $customer = Customer::factory()->create(['email' => 'tester@example.com']);

    $this->artisan('users:purge-test-accounts --apply --force')->assertSuccessful();

    expect(Customer::query()->find($customer->getKey()))->toBeNull()
        ->and(DB::table('users')->where('id', $customer->getKey())->value('deleted_at'))->not->toBeNull();
});

it('never touches an account on a real domain', function (): void {
    $customer = Customer::factory()->create(['email' => 'musteri@raftabul.com']);

    $this->artisan('users:purge-test-accounts --apply --force')->assertSuccessful();

    expect(Customer::query()->find($customer->getKey()))->not->toBeNull();
});

it('keeps a test account that has history', function (): void {
    /*
    | A points entry is history in the sense that matters: another table points
    | at this customer, and a soft-deleted user behind a real record is a
    | support call rather than a tidy database.
    */
    $withHistory = Customer::factory()->create(['email' => 'buyer@example.com']);
    $empty = Customer::factory()->create(['email' => 'empty@example.com']);

    DB::table('loyalty_ledger')->insert([
        'uuid' => (string) Str::uuid(),
        'customer_uuid' => $withHistory->uuid,
        'points' => 100,
        'source_type' => 'order',
        'source_uuid' => (string) Str::uuid(),
        'created_at' => now(),
    ]);

    $this->artisan('users:purge-test-accounts --apply --force')->assertSuccessful();

    expect(Customer::query()->find($withHistory->getKey()))->not->toBeNull()
        ->and(Customer::query()->find($empty->getKey()))->toBeNull();
});

it('does nothing when no domain defines a test account', function (): void {
    config()->set('mail.blocked_recipient_domains', []);

    $customer = Customer::factory()->create(['email' => 'tester@example.com']);

    $this->artisan('users:purge-test-accounts --apply --force')->assertSuccessful();

    expect(Customer::query()->find($customer->getKey()))->not->toBeNull();
});
