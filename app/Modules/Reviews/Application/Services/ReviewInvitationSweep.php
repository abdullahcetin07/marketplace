<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Services;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Models\Customer;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewRequest;
use App\Modules\Reviews\Infrastructure\Notifications\ReviewRequestedNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Invites buyers to review what they were delivered (ADR-087).
 *
 * **A SWEEP, NOT A DELAYED JOB.** The obvious shape — listen for
 * `ShipmentDelivered`, dispatch a job with a three-day delay — puts the platform's
 * review funnel inside a queue for three days, where a restarted worker, a flushed
 * Redis or a changed setting loses it silently. A sweep holds no state between
 * runs: it asks the same question every night and the answer moves on its own.
 * This is the ADR-072 shape, chosen for the ADR-072 reason.
 *
 * **THE DELAY IS THE POINT.** Asking on the doorstep asks about a parcel, not a
 * product. `reviews.request_delay_days` (default 3) is an operator setting rather
 * than a constant because the right wait differs by what is being sold.
 *
 * **IDEMPOTENT TWICE OVER.** Order re-offers every delivered line every night by
 * design — a reader that filtered on `review_requests` would be Order reaching
 * into Reviews — so this filters, and `review_requests.order_line_uuid` is UNIQUE
 * underneath. The check alone is a race between two runs; the constraint alone is
 * an exception in a sweep. Both, and the constraint violation is caught and
 * counted rather than thrown.
 *
 * **AN OPT-OUT IS RECORDED, NOT SKIPPED.** A buyer who unsubscribed is written
 * down as suppressed, so the sweep stops re-evaluating them nightly forever, and
 * the count is the only measure of what opt-out costs the review funnel.
 *
 * IT IMPORTS NO MODULE. Delivered lines arrive through `OrderQueryContract`, the
 * "already reviewed" test is this module's own table, and the recipient is an
 * `App\Models\Customer` — the authentication tier every module may reach.
 */
final class ReviewInvitationSweep
{
    public function __construct(
        private readonly OrderQueryContract $orders,
    ) {}

    /**
     * @return array{
     *     eligible: int,
     *     invited: int,
     *     already_reviewed: int,
     *     already_asked: int,
     *     suppressed: int,
     *     no_customer: int,
     * }
     */
    public function run(?Carbon $now = null, int $limit = 500): array
    {
        $report = [
            'eligible' => 0,
            'invited' => 0,
            'already_reviewed' => 0,
            'already_asked' => 0,
            'suppressed' => 0,
            'no_customer' => 0,
        ];

        if (! (bool) settings('reviews.request_enabled', true)) {
            return $report;
        }

        $now ??= Carbon::now();
        $delay = max(0, (int) settings('reviews.request_delay_days', 3));

        $lines = $this->orders->deliveredLinesForReviewInvitation(
            $now->copy()->subDays($delay),
            $limit,
        );

        if ($lines === []) {
            return $report;
        }

        $report['eligible'] = count($lines);

        $lineUuids = array_column($lines, 'order_line_uuid');

        // Both exclusions batched: a sweep that asked per row would be two
        // queries per delivered line, every night, forever.
        $reviewed = Review::query()
            ->whereIn('order_line_uuid', $lineUuids)
            ->pluck('order_line_uuid')
            ->flip();

        $asked = ReviewRequest::query()
            ->whereIn('order_line_uuid', $lineUuids)
            ->pluck('order_line_uuid')
            ->flip();

        foreach ($lines as $line) {
            if (isset($reviewed[$line['order_line_uuid']])) {
                $report['already_reviewed']++;

                continue;
            }

            if (isset($asked[$line['order_line_uuid']])) {
                $report['already_asked']++;

                continue;
            }

            $customer = Customer::query()->where('uuid', $line['customer_uuid'])->first();

            if ($customer === null) {
                $report['no_customer']++;

                continue;
            }

            /*
            | THE OPT-OUT IS CHECKED HERE AS WELL AS IN `BaseNotification`.
            |
            | The base class filters CHANNELS, so an unsubscribed buyer would get
            | a notification with no channels — nothing sent, but also nothing
            | said about why, and the row would be recorded as an invitation that
            | went out. Asking first is what lets the difference be written down.
            */
            if ($customer->hasOptedOutOf(NotificationType::Mail, ReviewRequestedNotification::class)) {
                $this->record($line, suppressedReason: 'opted_out');
                $report['suppressed']++;

                continue;
            }

            if (! $this->record($line)) {
                $report['already_asked']++;

                continue;
            }

            $customer->notify(new ReviewRequestedNotification(
                $line['product_title'],
                $this->urlFor(),
            ));

            $report['invited']++;
        }

        return $report;
    }

    /**
     * Write the "handled" row, or report that another run beat us to it.
     *
     * **THE ROW IS WRITTEN BEFORE THE MAIL IS QUEUED**, deliberately. The two
     * orderings fail differently: record-then-send can lose an invitation if the
     * queue write fails, send-then-record can send the SAME invitation every night
     * until the record succeeds. A buyer who is never asked does not notice; a
     * buyer asked nightly unsubscribes.
     *
     * @param array{order_line_uuid: string, customer_uuid: string, product_uuid: string, ...} $line
     */
    private function record(array $line, ?string $suppressedReason = null): bool
    {
        try {
            ReviewRequest::query()->create([
                'order_line_uuid' => $line['order_line_uuid'],
                'customer_uuid' => $line['customer_uuid'],
                'product_uuid' => $line['product_uuid'],
                'sent_at' => $suppressedReason === null ? Carbon::now() : null,
                'suppressed_reason' => $suppressedReason,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Two runs overlapped. The constraint is what makes that harmless.
            return false;
        }
    }

    /**
     * Where the invitation sends them.
     *
     * The orders page rather than a deep link to one review form: the storefront
     * owns that flow and its URL, and a backend guessing at a frontend route is
     * how a mail campaign starts 404ing after a redesign (ADR-025 — the backend
     * stays frontend-agnostic and composes from configuration).
     */
    private function urlFor(): string
    {
        $base = rtrim((string) config('marketplace.frontend_url'), '/');
        $path = (string) config('marketplace.frontend.orders_path', '/hesap/siparislerim');

        return $base.'/'.ltrim($path, '/');
    }
}
