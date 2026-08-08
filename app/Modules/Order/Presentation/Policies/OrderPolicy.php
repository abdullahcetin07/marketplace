<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Policies;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Models\User;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises orders — for THREE audiences with three different relationships to
 * the same row (§7).
 *
 *   CUSTOMER — owns it. The only actor on this platform who is authorised by
 *              being the SUBJECT of a record rather than by holding a permission
 *              or a membership. `owns()` on `BasePolicy` defaults to false
 *              (CLAUDE.md), which is why this policy answers ownership itself.
 *   SELLER   — sells it. Authorised by membership of the organization the order
 *              was placed with, resolved through the Core contract — Order
 *              imports no Organization model (ADR-040).
 *   ADMIN    — oversees it, cross-org, via `order.*` Spatie permissions.
 *
 * THE ASYMMETRY IN WHAT EACH MAY CANCEL IS THE INTERESTING PART:
 *
 *   - A customer may cancel their own order while nothing has shipped, which is
 *     everything this sprint can reach. This method NARROWS when Shipping exists;
 *     `OrderStatus::isCancellableByCustomer()` is where, so it changes in one
 *     place rather than at three call sites.
 *   - A seller may cancel an order placed with them — refusing an order is a real
 *     merchant decision (out of stock, cannot fulfil) and the alternative is a
 *     support ticket for every one.
 *   - An admin may cancel anything, and needs `order.cancel` for it: that is the
 *     reactive lever, the Offer-suspension shape.
 *
 * NOBODY MAY EDIT AN ORDER. There is no `update` here and no action behind one:
 * the lines are immutable (ADR-053) and the totals are written once. A wrong
 * order is cancelled and re-placed, which leaves both facts on the record.
 *
 * @see docs/modules/Order.md §7
 */
final class OrderPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
        private readonly StoreQueryContract $stores,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): Response
    {
        return match ($user->type) {
            UserType::Admin => $this->adminAbility($user, 'order.view_any'),
            // A seller and a customer are both confined by the surface's own query
            // scope — a customer to their own orders, a seller to their
            // organizations' — rather than by a permission neither can hold in a
            // scoped way.
            default => Response::allow(),
        };
    }

    public function view(User $user, Order $order): Response
    {
        return match ($user->type) {
            UserType::Admin => $this->adminAbility($user, 'order.view'),
            UserType::Customer => $this->owns($user, $order),
            // Seller is the remaining actor type, so it is the default rather
            // than a fourth arm PHPStan can prove unreachable.
            default => $this->sells($user, $order),
        };
    }

    /**
     * Cancelling — the one write any of the three may perform.
     */
    public function cancel(User $user, Order $order): Response
    {
        if ($order->status->isTerminal()) {
            // Already cancelled. Denied at the policy so a UI hides the button,
            // while the ACTION still treats a repeat as a silent no-op — a
            // double-clicked button must not produce an error page.
            return Response::deny(__('order.errors.already_cancelled'));
        }

        if (! $order->status->isCancellableWithoutRefund()) {
            /*
            | A PAID ORDER IS NOT THIS BUTTON'S (ADR-065). It is cancelled by
            | REFUNDING it — Payment's `CancelOrderLinesAction`, reached through
            | the Core command port — which returns the money, reverses the
            | commission and restocks before the order reaches `Cancelled`.
            | Denied here so the plain lever disappears from every surface rather
            | than throwing when somebody presses it.
            */
            return Response::deny(__('order.errors.paid_needs_refund'));
        }

        return match ($user->type) {
            UserType::Admin => $this->adminAbility($user, 'order.cancel'),
            UserType::Customer => $this->customerMayCancel($user, $order),
            default => $this->sells($user, $order),
        };
    }

    /**
     * Placing a purchase — the customer's, and only theirs.
     *
     * NOT A SELLER'S AND NOT AN ADMIN'S, deliberately: placing commits stock and
     * creates a debt in the customer's name (ADR-054). An operator doing that on
     * someone's behalf is not oversight, it is buying for them.
     */
    public function place(User $user, Order $order): Response
    {
        return $user->type === UserType::Customer
            ? $this->owns($user, $order)
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * ASKING the seller to cancel a paid order (ADR-065, C2).
     *
     * **CUSTOMERS ONLY, AND SEPARATE FROM `cancel` FOR THE OPPOSITE REASON
     * `cancelLines` IS.** That one is the seller's lever and this is the buyer's,
     * and neither is `cancel` widened: `cancel` ENDS an order, while this one
     * only asks somebody else to. A buyer who could reach `cancel` on a paid
     * order would be cancelling a sale the seller may be halfway through packing,
     * which is the exact thing ADR-065 refuses.
     *
     * IT CHECKS OWNERSHIP AND NOTHING ELSE. Whether the order is paid, whether
     * the parcel has left and whether a request is already open are all the
     * ACTION's, asked once where they cannot be forgotten — a policy that also
     * asked would be a second copy of three rules, and the copy is what goes
     * stale.
     */
    public function requestCancellation(User $user, Order $order): Response
    {
        return $user->type === UserType::Customer
            ? $this->owns($user, $order)
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * Answering one — the seller's side of the same exchange (ADR-065, C2).
     *
     * SELLERS ONLY. An admin reversing a paid order has Payment's refund surface,
     * which is ability-gated and audited as the money operation it is; deciding
     * whether a merchant can spare the goods is the merchant's call.
     */
    public function decideCancellation(User $user, Order $order): Response
    {
        return match ($user->type) {
            UserType::Admin, UserType::Customer => Response::deny(__('errors.forbidden')),
            default => $this->sells($user, $order),
        };
    }

    /**
     * ASKING to send a delivered order back (ADR-073).
     *
     * **CUSTOMERS ONLY, AND IT NO LONGER MOVES MONEY.** Under ADR-064 the
     * equivalent button refunded on the spot, so the ability guarded a payment;
     * now it guards a REQUEST, and the seller's completion is what pays. The
     * check is the same either way — is this your order — but what it protects
     * has changed, which is worth knowing before anyone widens it.
     *
     * IT CHECKS OWNERSHIP AND NOTHING ELSE, exactly as `requestCancellation`
     * does. Delivery, the window, the quantities and whether a return is already
     * running are all the ACTION's, asked once where they cannot be forgotten. A
     * policy that also asked would be a second copy of four rules.
     */
    public function requestReturn(User $user, Order $order): Response
    {
        return $user->type === UserType::Customer
            ? $this->owns($user, $order)
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * Answering a return — approve, reject, or confirm the goods are back
     * (ADR-073).
     *
     * **SELLERS ONLY, AND ONE ABILITY FOR ALL THREE ANSWERS.** They are three
     * steps of one conversation the same merchant holds; splitting them would
     * invite a role that may approve a return but not complete it, which is a
     * seller who has promised a refund they cannot deliver.
     *
     * NOT AN ADMIN'S. An admin who must reverse a delivered order has Payment's
     * refund surface, ability-gated and audited as the money operation it is.
     * Judging whether a returned parcel is acceptable is the merchant's call —
     * they are the one holding it.
     *
     * **A SELLER EMPLOYEE MAY**, through `sells()` and the granted permission:
     * receiving returns is delegable warehouse work, the same as shipping.
     */
    public function decideReturn(User $user, Order $order): Response
    {
        return match ($user->type) {
            UserType::Admin, UserType::Customer => Response::deny(__('errors.forbidden')),
            default => $this->sells($user, $order),
        };
    }

    /**
     * Shedding a line of a PAID order the seller cannot fulfil (ADR-065, C1).
     *
     * **A SEPARATE ABILITY FROM `cancel`, NOT A WIDENING OF IT.** They read alike
     * and mean opposite things: `cancel` gives back a HOLD and zeroes the seller's
     * declared stock; this one sends real money back to a buyer, reverses a
     * commission and RESTOCKS. One ability covering both would mean the day
     * somebody adjusted the rules for one, they adjusted them for the other
     * without noticing.
     *
     * SELLERS ONLY, and deliberately not admins. An admin who needs to reverse a
     * paid order has the refund surface (Payment.md §8), which is ability-gated
     * and audited as the money operation it is; this is the merchant saying "I do
     * not have it", and it is theirs to say.
     *
     * IT DOES NOT CHECK THE PARCEL. Whether the shipment has left is Shipping's
     * fact and Payment's gate — asked once, behind the command port, where it
     * cannot be forgotten. A policy that also asked would be a second copy of the
     * rule, and the copy would be the one that went stale.
     */
    public function cancelLines(User $user, Order $order): Response
    {
        if ($order->status !== OrderStatus::Paid) {
            return Response::deny(__('errors.forbidden'));
        }

        return match ($user->type) {
            UserType::Admin, UserType::Customer => Response::deny(__('errors.forbidden')),
            default => $this->sells($user, $order),
        };
    }

    /**
     * THE ONLY OWNERSHIP CHECK ON THE PLATFORM THAT AUTHORISES A CUSTOMER.
     *
     * By internal id rather than uuid: the id is what the row carries as its
     * tenancy key and what every scoped query filters on, so comparing anything
     * else would authorise on a different fact than the query returned.
     */
    private function owns(User $user, Order $order): Response
    {
        return (int) $order->customer_id === (int) $user->getKey()
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    private function customerMayCancel(User $user, Order $order): Response
    {
        if (! $order->status->isCancellableByCustomer()) {
            return Response::deny(__('order.errors.not_cancellable'));
        }

        return $this->owns($user, $order);
    }

    /**
     * Whether this seller's user belongs to the organization the order was placed
     * with.
     *
     * THROUGH THE STORE, because the order carries the org as a UUID and the Core
     * authorization contract answers memberships in internal IDS (ADR-040). The
     * store's uuid is the one identifier both sides share: `StoreQueryContract`
     * turns it into the owning organization's id, and that is what membership is
     * asked about.
     *
     * READING IS THE LIGHTER BAR — active membership, not the management
     * capability — so a warehouse or support member of the company can see the
     * orders they are expected to pack and answer for, exactly as
     * `InventoryPolicy` lets them see the stock.
     */
    private function sells(User $user, Order $order): Response
    {
        $organizationId = $this->stores->organizationIdFor($order->store_uuid);

        if ($organizationId === null) {
            return Response::deny(__('errors.forbidden'));
        }

        return $this->authz->isActiveMember($user->getKey(), $organizationId)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    private function adminAbility(User $user, string $permission): Response
    {
        if ($user->type !== UserType::Admin) {
            return Response::deny(__('errors.forbidden'));
        }

        return $user->hasPermissionTo($permission, $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => $permission]));
    }
}
