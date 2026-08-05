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
use Illuminate\Support\Facades\Log;

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
            // HYPHENS STRIPPED. @see `merchantOid()` — PayTR refuses anything
            // that is not alphanumeric, and a uuid is not.
            'merchant_oid' => $this->merchantOid($intent->reference),
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
            /*
            | LOGGED BEFORE IT IS THROWN, AND THIS IS THE POINT OF THE METHOD
            | BELOW. `gatewayRejected` is not reportable — a PSP saying no is an
            | answer, not an incident — which means nothing about it ever reached
            | a log file, and the first real merchant account produced a rejection
            | nobody could see the reason for. A refusal that cannot be read is a
            | refusal that cannot be fixed.
            |
            | PayTR's `reason` is written for an OPERATOR ("MAĞAZA PARAMETRELERINI
            | KONTROL EDINIZ"), not for a buyer, and it is the single string that
            | separates "wrong credentials" from "wrong hash" from "store not
            | live". It goes to the log verbatim, with the request beside it.
            */
            $this->logRejection('get-token', $body, $fields, $response->status());

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

        // PAYTR'S FORM ON THE WIRE, because that is what the hash is computed
        // over — the platform's canonical uuid is restored below.
        $merchantOid = (string) ($raw['merchant_oid'] ?? '');
        $status = (string) ($raw['status'] ?? '');
        $totalAmount = (string) ($raw['total_amount'] ?? '');
        $posted = (string) ($raw['hash'] ?? '');

        $expected = base64_encode(hash_hmac(
            'sha256',
            $merchantOid.$config['merchant_salt'].$status.$totalAmount,
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
            // TRANSLATED BACK at the boundary: nothing outside this class has
            // ever heard of PayTR's identifier format.
            reference: $this->referenceFrom($merchantOid),
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
            'merchant_oid' => $this->merchantOid($refund->reference),
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

        if (($body['status'] ?? null) !== 'success') {
            // The same blind spot as `initiate`, on the path that gives money
            // back. @see `logRejection()`.
            $this->logRejection('refund', $body, $fields, $response->status());
        }

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
     * The platform's payment uuid, in the only shape PayTR will accept.
     *
     * **THE BUG THIS METHOD IS.** ADR-060 set `merchant_oid = payment.uuid`, which
     * is right — one identifier, ours, no second column that could disagree — and
     * the real API answers `merchant_oid alfanumerik olmalidir, ozel karakter
     * iceremez`. A uuid's hyphens are special characters. Every live get-token
     * call was refused; the P1 suite stayed green because a mocked PayTR accepts
     * anything, which is the standing limit of testing an adapter against a fake.
     *
     * STRIPPING THE HYPHENS RATHER THAN MINTING A SEPARATE ID. The 32 hex digits
     * are the same uuid — losslessly, reversibly, no state — so the decision that
     * our record and PayTR's share one identifier survives intact. A second
     * `merchant_oid` column would have introduced exactly the disagreement
     * `Payment::merchantReference()` exists to prevent.
     *
     * IT LIVES HERE AND NOWHERE ELSE, because this is the only class that knows
     * PayTR exists. `Payment::merchantReference()` still returns the uuid; the
     * provider's format is an adapter concern, translated on the way out and
     * translated back on the way in (`referenceFrom()`).
     *
     * @see docs/modules/Payment.md §4
     */
    private function merchantOid(string $reference): string
    {
        return str_replace('-', '', $reference);
    }

    /**
     * PayTR's `merchant_oid` back to the platform's uuid.
     *
     * THE INVERSE OF `merchantOid()`, and the reason the strip is safe. A callback
     * arrives naming the hyphen-free form; every consumer downstream looks a
     * Payment up by `uuid`, so the translation happens once, here, at the edge.
     *
     * ANYTHING THAT IS NOT 32 HEX DIGITS IS PASSED THROUGH UNTOUCHED. A payment
     * that predates this fix carries hyphens on PayTR's side, and inventing a
     * shape for a string this method does not recognise would turn a legitimate
     * callback into a lookup miss.
     */
    private function referenceFrom(string $merchantOid): string
    {
        if (preg_match('/^[0-9a-f]{32}$/i', $merchantOid) !== 1) {
            return $merchantOid;
        }

        $hex = strtolower($merchantOid);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    /**
     * Write down exactly what PayTR refused, and what it was asked.
     *
     * THE GAP THIS CLOSES. Every refusal in this module is a non-reportable
     * domain exception — correct, because a declined basket is not an incident —
     * but the consequence was that a merchant-configuration failure produced a
     * 422 to the buyer and NOTHING anywhere else. The first live get-token
     * rejection was undiagnosable from the server: the platform was taking no
     * money and the only record of why was in a response nobody had kept.
     *
     * WHAT GOES IN, AND WHAT DELIBERATELY DOES NOT. The provider's own `reason`
     * and `err_no` verbatim (they are the diagnosis), the HTTP status, and the
     * REQUEST fields that decide whether the hash could have matched — the field
     * ORDER of `paytr_token` is positional and separator-free, so "which values
     * went into it" is the only way to tell a credential problem from a
     * composition problem. `merchant_key` and `merchant_salt` never appear:
     * anyone holding them can forge a "payment succeeded" this platform would
     * believe (Payment.md §9), and a log file is not where they belong.
     *
     * The e-mail is masked for the same reason it is masked everywhere else — a
     * log is read by more people than a database.
     *
     * @param array<string, mixed> $body PayTR's decoded answer
     * @param array<string, string> $sent the hashed fields, credentials excluded
     */
    private function logRejection(string $operation, array $body, array $sent, int $status): void
    {
        Log::channel('errors')->error('PayTR refused a request', [
            'operation' => $operation,
            // THE ONE STRING THAT DIAGNOSES THIS. @see the class docblock's
            // reason table.
            'reason' => $body['reason'] ?? null,
            'err_no' => $body['err_no'] ?? $body['err_msg'] ?? null,
            'http_status' => $status,
            'merchant_oid' => $sent['merchant_oid'] ?? null,
            'payment_amount' => $sent['payment_amount'] ?? $sent['return_amount'] ?? null,
            'user_ip' => $sent['user_ip'] ?? null,
            'email' => isset($sent['email']) ? $this->maskEmail($sent['email']) : null,
            'currency' => $sent['currency'] ?? null,
            'test_mode' => $sent['test_mode'] ?? null,
            'no_installment' => $sent['no_installment'] ?? null,
            'max_installment' => $sent['max_installment'] ?? null,
            // Length only: the basket is base64 of the buyer's items and adds
            // nothing to a hash diagnosis beyond "was it there at all".
            'user_basket_length' => isset($sent['user_basket']) ? strlen($sent['user_basket']) : null,
            'merchant_id' => $sent['merchant_id'] ?? null,
        ]);
    }

    /**
     * `ayse@example.com` → `ay***@example.com`.
     */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        if ($at === false || $at < 2) {
            return '***';
        }

        return substr($email, 0, 2).'***'.substr($email, $at);
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
