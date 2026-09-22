<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Infrastructure\Notifications\RefundRefusedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Raises the alarm when the provider will not fund a refund (Payment.md §8).
 *
 * **THE FAILURE WAS OTHERWISE SILENT.** A seller pressing "gönderemiyorum" got
 * one sentence, the transaction rolled back, and nobody who could fix it heard
 * anything — because the fix is not in the code: PayTR refuses with `err_no 010`
 * when the merchant's net balance will not cover the amount. On production that
 * went unnoticed from 21 to 22 September while sellers were blocked.
 *
 * **IT NEVER BECOMES THE FAILURE.** The refusal is what the caller is about to
 * throw; a mail server having a bad day must not replace a diagnosable rejection
 * with an unrelated exception. Everything here is caught and logged.
 *
 * **NO RECIPIENT CONFIGURED IS A VALID STATE** — the log line still gets
 * written, which is exactly what this class had before it existed.
 */
final class RefundRefusalAlert
{
    public function announce(Payment $payment, int $amountMinor, ?string $detail): void
    {
        Log::channel('errors')->error('A refund was refused by the payment provider', [
            'payment_uuid' => $payment->uuid,
            'amount_minor' => $amountMinor,
            'detail' => $detail,
        ]);

        $recipient = (string) config('payment.alerts.recipient', '');

        if ($recipient === '') {
            return;
        }

        try {
            Notification::route('mail', $recipient)->notify(new RefundRefusedNotification(
                paymentUuid: $payment->uuid,
                amount: number_format($amountMinor / 100, 2, ',', '.'),
                currency: $payment->currency->code,
                detail: $detail,
            ));
        } catch (Throwable $exception) {
            Log::channel('errors')->warning('Could not send the refund-refused alert', [
                'payment_uuid' => $payment->uuid,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
