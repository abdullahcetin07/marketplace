<?php

declare(strict_types=1);

use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\DTOs\PaymentIntentDTO;
use App\Modules\Payment\Domain\DTOs\PaymentRefundDTO;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The PayTR adapter (ADR-060, Payment.md §3)
|--------------------------------------------------------------------------
|
| The only class on the platform that has heard of PayTR, and the only place a
| mistake produces "payments silently never work" rather than an exception. Both
| hashes are positional and separator-free, so one field out of order yields a
| plausible-looking string that simply never matches — which is why they are pinned
| here against independently-computed expectations rather than against the
| implementation's own output.
|
| MONEY IS THE OTHER THEME. PayTR's `payment_amount` is kuruş, like the platform's
| (ADR-005), so the integer travels end to end. Its REFUND field is lira, which is
| the module's one unit conversion — and it is built with `intdiv`, because a
| float there is the financial bug the money rule exists to prevent.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();

    config([
        'payment.paytr.merchant_id' => '123456',
        'payment.paytr.merchant_key' => 'test-key',
        'payment.paytr.merchant_salt' => 'test-salt',
        'payment.paytr.test_mode' => true,
        'payment.paytr.no_installment' => false,
        'payment.paytr.max_installment' => 0,
    ]);
});

function gateway(): PaymentGatewayContract
{
    return app(PaymentGatewayContract::class);
}

it('sends the amount in kuruş, untouched', function (): void {
    Http::fake([
        '*' => Http::response(['status' => 'success', 'token' => 'iframe-token']),
    ]);

    $session = gateway()->initiate(new PaymentIntentDTO(
        reference: '39a87919-589f-46f8-836c-d4e6bd691f06',
        // 1 299,90 TL. What must reach PayTR is 129990 — not "1299.90", not
        // 1299.9, and above all not a float that could arrive as 1299.8999999.
        amountMinor: 129_990,
        currencyCode: 'TRY',
        buyerEmail: 'alici@example.com',
        buyerName: 'Ayşe Yılmaz',
        buyerAddress: '-',
        buyerPhone: '-',
        buyerIp: '10.0.0.1',
        basket: [['name' => 'Pamuklu Tişört', 'price' => '129.99', 'quantity' => 1]],
    ));

    expect($session->token)->toBe('iframe-token');

    Http::assertSent(function ($request): bool {
        expect($request['payment_amount'])->toBe('129990')
            ->and($request['currency'])->toBe('TL')
            /*
             * HYPHEN-FREE, AND THIS ASSERTION IS THE BUG (2026-08-05). The
             * fixture used to be the string 'a-payment-uuid', which has hyphens
             * and is not a uuid, so it proved nothing about either. The real API
             * answers `merchant_oid alfanumerik olmalidir, ozel karakter
             * iceremez` — every live get-token call was refused while this suite
             * stayed green against a mock that accepts anything.
             */
            ->and($request['merchant_oid'])->toBe('39a87919589f46f8836cd4e6bd691f06')
            // Test mode travels as PayTR's own flag, not as a boolean.
            ->and($request['test_mode'])->toBe('1');

        return true;
    });
});

it('builds the get-token hash over PayTR’s documented fields, in order', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'token' => 't'])]);

    gateway()->initiate(new PaymentIntentDTO(
        reference: '0d5b2f1a-1111-4222-8333-444455556666',
        amountMinor: 5_000,
        currencyCode: 'TRY',
        buyerEmail: 'a@b.com',
        buyerName: 'A',
        buyerAddress: '-',
        buyerPhone: '-',
        buyerIp: '1.2.3.4',
        basket: [],
    ));

    Http::assertSent(function ($request): bool {
        /*
         * COMPUTED INDEPENDENTLY HERE, from PayTR's documented order:
         * merchant_id · user_ip · merchant_oid · email · payment_amount ·
         * user_basket · no_installment · max_installment · currency · test_mode,
         * then the salt, HMAC-SHA256 with the key, base64.
         *
         * Asserting against a re-implementation rather than against the class's
         * own method is the point: a reordering that broke every real payment
         * would still agree with itself.
         */
        $expected = base64_encode(hash_hmac(
            'sha256',
            // The hash is over what actually TRAVELS — the hyphen-free oid — or
            // PayTR computes a different one and refuses the request.
            '123456'.'1.2.3.4'.'0d5b2f1a111142228333444455556666'.'a@b.com'.'5000'.$request['user_basket'].'0'.'0'.'TL'.'1'.'test-salt',
            'test-key',
            true,
        ));

        expect($request['paytr_token'])->toBe($expected);

        return true;
    });
});

it('verifies a callback hash and rejects a forged one', function (): void {
    $good = base64_encode(hash_hmac('sha256', 'oid-9'.'test-salt'.'success'.'2500', 'test-key', true));

    $verified = gateway()->verifyCallback([
        'merchant_oid' => 'oid-9',
        'status' => 'success',
        'total_amount' => '2500',
        'hash' => $good,
    ]);

    expect($verified->verified)->toBeTrue()
        ->and($verified->successful)->toBeTrue()
        // Kuruş back out, as an integer.
        ->and($verified->amountMinor)->toBe(2500);

    $forged = gateway()->verifyCallback([
        'merchant_oid' => 'oid-9',
        'status' => 'success',
        'total_amount' => '2500',
        'hash' => base64_encode('whatever'),
    ]);

    /*
     * `verified` AND `successful` ARE SEPARATE FIELDS, and this is why. A forged
     * payload says "success" too; a single "is it ok" boolean would invite a
     * caller to read the wrong one.
     */
    expect($forged->verified)->toBeFalse()
        ->and($forged->successful)->toBeTrue();
});

it('takes PayTR’s hyphen-free oid back to the platform’s uuid', function (): void {
    /*
     * THE OTHER HALF OF THE 2026-08-05 FIX. `initiate` strips the hyphens because
     * PayTR refuses them; the callback therefore names the payment in PayTR's
     * form, and every consumer downstream looks a Payment up by `uuid`. So the
     * translation happens once, here, at the edge — and the hash is still checked
     * against what actually travelled.
     */
    $uuid = '39a87919-589f-46f8-836c-d4e6bd691f06';
    $oid = '39a87919589f46f8836cd4e6bd691f06';

    $result = gateway()->verifyCallback([
        'merchant_oid' => $oid,
        'status' => 'success',
        'total_amount' => '159000',
        'hash' => base64_encode(hash_hmac('sha256', $oid.'test-salt'.'success'.'159000', 'test-key', true)),
    ]);

    expect($result->verified)->toBeTrue()
        ->and($result->reference)->toBe($uuid);
});

it('passes an unrecognised oid through untouched', function (): void {
    /*
     * A PAYMENT THAT PREDATES THE FIX carries hyphens on PayTR's side, and a
     * callback for it must still resolve. Inventing a shape for a string this
     * translation does not recognise would turn a legitimate callback into a
     * lookup miss — money taken, order never confirmed.
     */
    $legacy = '39a87919-589f-46f8-836c-d4e6bd691f06';

    $result = gateway()->verifyCallback([
        'merchant_oid' => $legacy,
        'status' => 'success',
        'total_amount' => '100',
        'hash' => base64_encode(hash_hmac('sha256', $legacy.'test-salt'.'success'.'100', 'test-key', true)),
    ]);

    expect($result->verified)->toBeTrue()
        ->and($result->reference)->toBe($legacy);
});

it('sends the hyphen-free oid on a refund too', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => '9'])]);

    gateway()->refund(new PaymentRefundDTO(
        reference: '39a87919-589f-46f8-836c-d4e6bd691f06',
        amountMinor: 1_000,
    ));

    // PayTR knows the charge by ONE name, and a refund that used a different one
    // would be refused exactly as the original get-token was.
    Http::assertSent(fn ($request): bool => $request['merchant_oid'] === '39a87919589f46f8836cd4e6bd691f06');
});

it('signs a refund with merchant_id FIRST — the bug that made every refund fail', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => '9'])]);

    gateway()->refund(new PaymentRefundDTO(
        reference: '39a87919-589f-46f8-836c-d4e6bd691f06',
        amountMinor: 1_000,
    ));

    /*
     * **A REGRESSION TEST FOR A LIVE BUG THAT WAS MISREAD FOR TWO DAYS.** The
     * token was hashed over `merchant_oid + return_amount + salt`, omitting the
     * `merchant_id` that PayTR's iade API puts first — the same leading field the
     * get-token hash has always had. Live PayTR answered `err_no: 004`,
     * "paytr_token gonderilmedi veya gecersiz", on every refund the platform has
     * ever attempted, and it was recorded as a merchant-account permission
     * problem because a wrong hash and a disabled capability look identical from
     * outside.
     *
     * COMPUTED HERE INDEPENDENTLY rather than compared to a captured string, so
     * this fails if either side of the protocol is edited — which is the whole
     * point of a positional, separator-free hash whose only failure signal is a
     * refusal.
     */
    Http::assertSent(function ($request): bool {
        $expected = base64_encode(hash_hmac(
            'sha256',
            (string) config('payment.paytr.merchant_id')
                .'39a87919589f46f8836cd4e6bd691f06'
                .'10.00'
                .(string) config('payment.paytr.merchant_salt'),
            (string) config('payment.paytr.merchant_key'),
            true,
        ));

        expect($request['paytr_token'])->toBe($expected);

        // AND THE OLD, WRONG ONE IS NOT WHAT WE SEND.
        expect($request['paytr_token'])->not->toBe(base64_encode(hash_hmac(
            'sha256',
            '39a87919589f46f8836cd4e6bd691f06'
                .'10.00'
                .(string) config('payment.paytr.merchant_salt'),
            (string) config('payment.paytr.merchant_key'),
            true,
        )));

        return true;
    });
});

it('writes down what PayTR refused, and puts it in the exception message', function (): void {
    Http::fake(['*' => Http::response([
        'status' => 'failed',
        'reason' => 'merchant_oid alfanumerik olmalidir, ozel karakter iceremez',
    ])]);

    Log::shouldReceive('channel')->with('errors')->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            /*
             * THE OBSERVABILITY GAP THIS CLOSES. `gatewayRejected` is not
             * reportable — a PSP saying no is an answer, not an incident — so
             * nothing about a live rejection ever reached a log file, and the
             * first real merchant account produced a refusal nobody could read
             * the reason for.
             */
            expect($message)->toBe('PayTR refused a request')
                ->and($context['reason'])->toBe('merchant_oid alfanumerik olmalidir, ozel karakter iceremez')
                ->and($context['operation'])->toBe('get-token');

            // AND THE CREDENTIALS NEVER APPEAR. Anyone holding them can forge a
            // "payment succeeded" this platform would believe.
            expect(json_encode($context))->not->toContain('test-key')
                ->and(json_encode($context))->not->toContain('test-salt');

            return true;
        });

    try {
        gateway()->initiate(new PaymentIntentDTO(
            reference: '39a87919-589f-46f8-836c-d4e6bd691f06',
            amountMinor: 100,
            currencyCode: 'TRY',
            buyerEmail: 'ayse@example.com',
            buyerName: 'A',
            buyerAddress: '-',
            buyerPhone: '-',
            buyerIp: '1.2.3.4',
            basket: [],
        ));

        $this->fail('A refused get-token must throw.');
    } catch (PaymentException $exception) {
        /*
         * TWO DIFFERENT STRINGS, DELIBERATELY. `getMessage()` is what a stack
         * trace carries and it holds the operator's diagnosis; `userMessage()` is
         * what the shopper reads and it stays the translation. Before the split
         * they were the same string and the diagnosis was simply absent.
         */
        expect($exception->getMessage())->toContain('alfanumerik')
            ->and($exception->userMessage())->toBe(__('payment.errors.gateway_rejected'))
            ->and($exception->userMessage())->not->toContain('alfanumerik');
    }
});

it('treats a missing hash as unverified rather than as a match', function (): void {
    // An empty posted hash must never compare equal to anything.
    expect(gateway()->verifyCallback(['merchant_oid' => 'x', 'status' => 'success'])->verified)
        ->toBeFalse();
});

it('carries PayTR’s own decline words through, and nothing when it said none', function (): void {
    $hash = base64_encode(hash_hmac('sha256', 'oid-2'.'test-salt'.'failed'.'100', 'test-key', true));

    $result = gateway()->verifyCallback([
        'merchant_oid' => 'oid-2',
        'status' => 'failed',
        'total_amount' => '100',
        'hash' => $hash,
        'failed_reason_code' => '0',
        'failed_reason_msg' => 'Yetersiz bakiye',
    ]);

    // Verbatim: a support agent quotes this back to the provider, and a
    // normalised taxonomy would lose the only string PayTR recognises.
    expect($result->failureReason)->toBe('0 Yetersiz bakiye');

    $silent = gateway()->verifyCallback([
        'merchant_oid' => 'oid-2',
        'status' => 'failed',
        'total_amount' => '100',
        'hash' => $hash,
    ]);

    // Null rather than an empty string, so the column stays honest.
    expect($silent->failureReason)->toBeNull();
});

it('converts kuruş to lira for a refund without ever building a float', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => '77'])]);

    // PayTR's one inconsistency: `payment_amount` is kuruş on the way in,
    // `return_amount` is lira on the way back. 1999 kuruş must be "19.99" —
    // `/ 100` would risk 19.989999999999999.
    gateway()->refund(new PaymentRefundDTO(reference: 'oid-3', amountMinor: 1_999));

    Http::assertSent(function ($request): bool {
        expect($request['return_amount'])->toBe('19.99');

        return true;
    });

    Http::fake(['*' => Http::response(['status' => 'success'])]);

    // And the case that catches a naive implementation: a whole number of lira
    // must keep its two decimal places, and a single-digit kuruş must be padded.
    gateway()->refund(new PaymentRefundDTO(reference: 'oid-4', amountMinor: 5_000));
    Http::assertSent(fn ($request): bool => $request['return_amount'] === '50.00');

    gateway()->refund(new PaymentRefundDTO(reference: 'oid-5', amountMinor: 1_205));
    Http::assertSent(fn ($request): bool => $request['return_amount'] === '12.05');
});

it('refuses the session when PayTR says no', function (): void {
    Http::fake(['*' => Http::response(['status' => 'failed', 'reason' => 'invalid merchant'])]);

    // An ANSWER, not an outage — so it is refused and not reported as an
    // incident. The provider's words go to support, never to the buyer.
    expect(fn () => gateway()->initiate(new PaymentIntentDTO(
        reference: 'oid-6',
        amountMinor: 100,
        currencyCode: 'TRY',
        buyerEmail: 'a@b.com',
        buyerName: 'A',
        buyerAddress: '-',
        buyerPhone: '-',
        buyerIp: '1.1.1.1',
    )))->toThrow(PaymentException::class);
});

it('never sends anything that could be card data', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'token' => 't'])]);

    gateway()->initiate(new PaymentIntentDTO(
        reference: 'oid-7',
        amountMinor: 100,
        currencyCode: 'TRY',
        buyerEmail: 'a@b.com',
        buyerName: 'A',
        buyerAddress: '-',
        buyerPhone: '-',
        buyerIp: '1.1.1.1',
    ));

    Http::assertSent(function ($request): bool {
        /*
         * THE MODULE'S HARDEST RULE, asserted rather than assumed. The card and
         * its 3-D Secure step live inside PayTR's iframe; there is no field in
         * the intent that could hold a PAN, and this proves none appears by
         * accident either.
         */
        foreach (['cc_owner', 'card_number', 'expiry_month', 'expiry_year', 'cvv'] as $forbidden) {
            expect($request->data())->not->toHaveKey($forbidden);
        }

        return true;
    });
});
