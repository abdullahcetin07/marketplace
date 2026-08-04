<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Gateways;

use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\DTOs\GatewayRefundResultDTO;
use App\Modules\Payment\Domain\DTOs\GatewayResultDTO;
use App\Modules\Payment\Domain\DTOs\GatewaySessionDTO;
use App\Modules\Payment\Domain\DTOs\PaymentIntentDTO;
use App\Modules\Payment\Domain\DTOs\PaymentRefundDTO;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * PayTR, in its iFrame API shape — the only implementation of the gateway port
 * (ADR-060, Payment.md §3).
 *
 * THE ONLY CLASS ON THE PLATFORM THAT KNOWS PAYTR EXISTS. Everything else talks
 * to `PaymentGatewayContract`, so a second provider is a second file in this
 * directory rather than a change anywhere in the domain. If you find the string
 * "paytr" outside this namespace and `config/payment.php`, that is a boundary
 * violation you can see in an import line.
 *
 * NO CARD DATA REACHES THIS CODE. `initiate()` asks PayTR for a token; the
 * buyer's browser exchanges it for an iframe, and the card and its 3-D Secure step
 * happen inside PayTR's page. This class sees an amount going out and a result
 * coming back.
 *
 * THE TWO HASHES ARE THE WHOLE SECURITY MODEL, and they are not the same hash.
 * Both are HMAC-SHA256 with `merchant_key`, both fold in `merchant_salt`, and
 * both are base64 — but the FIELDS and their ORDER differ, and the order is
 * positional with no separators, so a single field out of place produces a
 * plausible-looking hash that simply never matches. Each one is built in its own
 * method with the field order written out, because the failure mode of getting it
 * wrong is "payments silently never work" rather than an exception.
 *
 * `payment_amount` IS KURUŞ, which is the happy accident that removes the most
 * dangerous conversion in the module: PayTR's unit is the platform's unit
 * (ADR-005), so the integer travels end to end and no float is ever constructed.
 *
 * @see App\Core\Domain\Contracts\PaymentGatewayContract
 * @see docs/modules/Payment.md §3
 */
final class PayTrGateway implements PaymentGatewayContract
{
    /**
     * How long to wait on PayTR before treating it as unreachable.
     *
     * Short on purpose: `initiate` runs inside a buyer's request, and a shopper
     * staring at a spinner for 30 seconds has already left. An unreachable PSP is
     * a reportable incident (`PaymentException::gatewayUnavailable`), not
     * something to hide behind a long timeout.
     */
    private const int TIMEOUT_SECONDS = 15;

    public function initiate(PaymentIntentDTO $intent): GatewaySessionDTO
    {
        $config = $this->config();

        $basket = base64_encode((string) json_encode(
            array_map(
                static fn (array $line): array => [$line['name'], $line['price'], $line['quantity']],
                $intent->basket,
            ),
            JSON_UNESCAPED_UNICODE,
        ));

        $fields = [
            'merchant_id' => $config['merchant_id'],
            'user_ip' => $intent->buyerIp,
            'merchant_oid' => $intent->reference,
            'email' => $intent->buyerEmail,
            // Integer kuruş, as a string on the wire. Never formatted, never
            // divided — see the class docblock.
            'payment_amount' => (string) $intent->amountMinor,
            'user_basket' => $basket,
            'no_installment' => $config['no_installment'] ? '1' : '0',
            'max_installment' => (string) $config['max_installment'],
            // PayTR's code for Turkish lira. The platform's own currency code is
            // carried separately on the Payment row; this is the provider's
            // vocabulary, which is exactly the kind of thing an adapter exists to
            // translate.
            'currency' => 'TL',
            'test_mode' => $config['test_mode'] ? '1' : '0',
        ];

        $response = $this->post($config['token_url'], [
            ...$fields,
            'paytr_token' => $this->tokenHash($fields, $config),
            'user_name' => $intent->buyerName,
            'user_address' => $intent->buyerAddress,
            'user_phone' => $intent->buyerPhone,
            'merchant_ok_url' => (string) config('payment.result_urls.success'),
            'merchant_fail_url' => (string) config('payment.result_urls.failure'),
            'timeout_limit' => (string) config('payment.paytr.timeout_minutes'),
            // 3-D Secure is not optional and is not configurable. Turning it off
            // would move liability for a fraudulent transaction onto the platform,
            // which is not a switch anyone should be able to flip from a .env.
            'debug_on' => $config['test_mode'] ? '1' : '0',
            'lang' => 'tr',
        ]);

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (($body['status'] ?? null) !== 'success') {
            // PayTR answered and said no — a bad merchant config, a basket it
            // will not take. An answer, not an outage, so not reportable.
            throw PaymentException::gatewayRejected((string) ($body['reason'] ?? 'unknown'));
        }

        return new GatewaySessionDTO(token: (string) ($body['token'] ?? ''));
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function verifyCallback(array $raw): GatewayResultDTO
    {
        $config = $this->config();

        $reference = (string) ($raw['merchant_oid'] ?? '');
        $status = (string) ($raw['status'] ?? '');
        $totalAmount = (string) ($raw['total_amount'] ?? '');
        $posted = (string) ($raw['hash'] ?? '');

        $expected = base64_encode(hash_hmac(
            'sha256',
            $reference.$config['merchant_salt'].$status.$totalAmount,
            $config['merchant_key'],
            true,
        ));

        /*
        | CONSTANT-TIME COMPARISON. `===` on strings leaks how many leading bytes
        | matched through timing, and this endpoint is public and can be probed as
        | often as an attacker likes. `hash_equals` is the one-word fix for an
        | attack that is otherwise entirely practical against a retry-tolerant
        | callback.
        */
        $verified = $posted !== '' && hash_equals($expected, $posted);

        return new GatewayResultDTO(
            verified: $verified,
            // Reported as PayTR sent it. A caller that ignores `verified` and acts
            // on this is the bug the two-field shape exists to make obvious.
            successful: $status === 'success',
            reference: $reference,
            amountMinor: ctype_digit($totalAmount) ? (int) $totalAmount : 0,
            failureReason: $status === 'success' ? null : $this->failureReason($raw),
            providerReference: isset($raw['payment_id']) ? (string) $raw['payment_id'] : null,
        );
    }

    public function refund(PaymentRefundDTO $refund): GatewayRefundResultDTO
    {
        $config = $this->config();

        /*
        | THE REFUND AMOUNT IS LIRA, NOT KURUŞ — PayTR's one inconsistency, and the
        | reason this conversion exists in exactly one place. `return_amount` is a
        | decimal string of lira while `payment_amount` on the way in was kuruş.
        | Built with integer division and `str_pad` rather than `/ 100`, because a
        | float here is the financial bug the platform's money rule exists to
        | prevent (ADR-005) — 1999 kuruş must be "19.99", never 19.989999999.
        */
        $lira = intdiv($refund->amountMinor, 100);
        $kurus = str_pad((string) ($refund->amountMinor % 100), 2, '0', STR_PAD_LEFT);

        $fields = [
            'merchant_id' => $config['merchant_id'],
            'merchant_oid' => $refund->reference,
            'return_amount' => $lira.'.'.$kurus,
        ];

        $response = $this->post($config['refund_url'], [
            ...$fields,
            'paytr_token' => base64_encode(hash_hmac(
                'sha256',
                $fields['merchant_oid'].$fields['return_amount'].$config['merchant_salt'],
                $config['merchant_key'],
                true,
            )),
        ]);

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return new GatewayRefundResultDTO(
            successful: ($body['status'] ?? null) === 'success',
            amountMinor: $refund->amountMinor,
            failureReason: ($body['status'] ?? null) === 'success' ? null : (string) ($body['err_msg'] ?? 'unknown'),
            providerReference: isset($body['islem_id']) ? (string) $body['islem_id'] : null,
        );
    }

    /**
     * PayTR's decline explanation, as it sent it.
     *
     * KEPT VERBATIM rather than mapped to a platform code: a support agent quotes
     * this back to the provider, and a normalised failure taxonomy would be one
     * more thing to maintain that loses the only string PayTR recognises. Null
     * when it said nothing, so the column stays honest.
     *
     * @param array<string, mixed> $raw
     */
    private function failureReason(array $raw): ?string
    {
        $reason = trim(
            (string) ($raw['failed_reason_code'] ?? '').' '.(string) ($raw['failed_reason_msg'] ?? ''),
        );

        return $reason === '' ? null : $reason;
    }

    /**
     * The `paytr_token` for a get-token request.
     *
     * POSITIONAL AND SEPARATOR-FREE, in PayTR's documented order — which is why
     * it is spelled out here rather than built from `implode(array_values(...))`.
     * The field order is part of the protocol, and an array whose order happened
     * to change would produce a hash that is wrong in a way nothing reports: the
     * request is simply refused, forever, with no clue as to why.
     *
     * @param array<string, string> $fields
     * @param array<string, mixed> $config
     */
    private function tokenHash(array $fields, array $config): string
    {
        $concatenated = $fields['merchant_id']
            .$fields['user_ip']
            .$fields['merchant_oid']
            .$fields['email']
            .$fields['payment_amount']
            .$fields['user_basket']
            .$fields['no_installment']
            .$fields['max_installment']
            .$fields['currency']
            .$fields['test_mode'];

        return base64_encode(hash_hmac(
            'sha256',
            $concatenated.$config['merchant_salt'],
            (string) $config['merchant_key'],
            true,
        ));
    }

    /**
     * @param array<string, mixed> $form
     */
    private function post(string $url, array $form): \Illuminate\Http\Client\Response
    {
        try {
            return Http::asForm()->timeout(self::TIMEOUT_SECONDS)->post($url, $form);
        } catch (ConnectionException $exception) {
            // The platform is taking no money while this is true, so it is the one
            // reportable failure in this module.
            throw PaymentException::gatewayUnavailable($exception->getMessage());
        }
    }

    /**
     * @return array{merchant_id: string, merchant_key: string, merchant_salt: string, test_mode: bool, token_url: string, refund_url: string, no_installment: bool, max_installment: int}
     */
    private function config(): array
    {
        return [
            'merchant_id' => (string) config('payment.paytr.merchant_id'),
            'merchant_key' => (string) config('payment.paytr.merchant_key'),
            'merchant_salt' => (string) config('payment.paytr.merchant_salt'),
            'test_mode' => (bool) config('payment.paytr.test_mode'),
            'token_url' => (string) config('payment.paytr.token_url'),
            'refund_url' => (string) config('payment.paytr.refund_url'),
            'no_installment' => (bool) config('payment.paytr.no_installment'),
            'max_installment' => (int) config('payment.paytr.max_installment'),
        ];
    }
}
