<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Presentation\Middleware;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Marketing\Domain\Models\CheckoutSignal;
use App\Shared\Support\PublicKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Takes the browser's Meta match keys off the pay request (Marketing.md).
 *
 * **ATTACHED TO `api.v1.checkout.pay` IN routes/api.php**, the last request the
 * shopper's browser makes before PayTR takes over. Payment's controller knows
 * nothing about it; removing the line removes the capture.
 *
 * **BEFORE THE CONTROLLER, NOT AFTER.** A basket paid entirely with points settles
 * inside this very request and fires `PaymentSucceeded` synchronously — the row
 * has to exist by then or that Purchase goes out without it.
 *
 * **SO IT CHECKS OWNERSHIP ITSELF**, with the same Core read the controller uses:
 * it runs before the controller's guard, and a signed-in shopper holding someone
 * else's group uuid must not be able to write that basket's match keys.
 *
 * **WHAT IS KEPT (owner decision, 2026-09-15).** IP and user agent for every
 * payer — they are on the request itself and describe the transaction. `_fbp` /
 * `_fbc` only when the STOREFRONT sends them, which it does only after the shopper
 * accepted marketing cookies. No fbclid is ever captured for an un-consented visitor.
 *
 * **IT NEVER BLOCKS A PAYMENT.** Disabled → nothing. Any failure → reported, and
 * the request continues to the controller untouched.
 */
final class CaptureCheckoutSignals
{
    private const FBP = '/^fb\.\d\.\d{10,16}\.\d{1,20}$/';

    private const FBC = '/^fb\.\d\.\d{10,16}\.[A-Za-z0-9_.\-]{1,450}$/';

    public function __construct(private readonly OrderQueryContract $orders) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('marketing.meta.enabled')) {
            try {
                $this->capture($request);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $next($request);
    }

    private function capture(Request $request): void
    {
        $group = $request->route('group');
        $actor = current_actor();

        if (! is_string($group) || ! PublicKey::looksLikeUuid($group) || $actor === null) {
            return;
        }

        $customer = $this->orders->checkoutGroupCustomer($group);

        if ($customer === null || (int) $customer['id'] !== (int) $actor->getKey()) {
            return;
        }

        $ip = $request->ip();
        $userAgent = $request->userAgent();

        // A double-clicked pay racing to INSERT is absorbed by updateOrCreate itself
        // (createOrFirst re-reads on the unique violation), so it never reports.
        CheckoutSignal::query()->updateOrCreate(
            ['checkout_group_uuid' => $group],
            [
                'fbp' => $this->matching($request->input('fbp'), self::FBP),
                'fbc' => $this->matching($request->input('fbc'), self::FBC),
                'client_ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
                'client_user_agent' => is_string($userAgent) && $userAgent !== ''
                    ? mb_substr($userAgent, 0, 512)
                    : null,
            ],
        );
    }

    /**
     * The value when it has Meta's cookie shape; null for anything else, so a
     * client cannot park arbitrary text in the table.
     */
    private function matching(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
