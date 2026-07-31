<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\RemoveCartItemAction;
use App\Modules\Order\Application\Actions\UpdateCartItemAction;
use App\Modules\Order\Application\Services\CartPricingService;
use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Order\Presentation\Requests\AddCartItemRequest;
use App\Modules\Order\Presentation\Requests\UpdateCartItemRequest;
use App\Modules\Order\Presentation\Resources\CartResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's basket (§4).
 *
 * API-ONLY, WITH NO UI BEHIND IT YET. The storefront is a separate Next.js app
 * that does not exist (§0.8), so this surface ships and waits — the same
 * discipline that had Inventory's reservation contract ship before Order.
 *
 * EVERY ACTION IS SCOPED BY THE AUTHENTICATED CUSTOMER, never by an id in the
 * path. There is no `/carts/{id}` and there never should be: a basket is
 * singular per customer, so exposing an identifier would create a way to ask for
 * one that is not yours.
 *
 * A LINE IS ADDRESSED BY ITS UUID and resolved THROUGH the customer's own cart,
 * so guessing another basket's line uuid produces a 404 rather than somebody
 * else's item.
 *
 * NO POLICY CALLS, deliberately — unlike the order controller. There is no cart
 * belonging to anybody else in reach of these methods, so an ownership check
 * would be asking about a row the query could not have returned.
 *
 * @see docs/modules/Order.md §4
 */
final class CartController extends BaseController
{
    public function __construct(
        private readonly CartRepositoryContract $carts,
        private readonly CartPricingService $pricing,
        private readonly AddCartItemAction $add,
        private readonly UpdateCartItemAction $update,
        private readonly RemoveCartItemAction $remove,
    ) {}

    /**
     * GET /cart
     *
     * A customer with no basket gets an EMPTY ONE rather than a 404: "you have no
     * cart" is not a client error, and a storefront rendering a header badge
     * should not have to branch on it.
     */
    public function show(): JsonResponse
    {
        return $this->ok($this->payload($this->carts->forCustomer($this->customerId())));
    }

    /**
     * POST /cart/items
     */
    public function store(AddCartItemRequest $request): JsonResponse
    {
        $customer = $this->customer();

        $this->add->run((int) $customer->getKey(), $customer->uuid, $request->toDto());

        return $this->created($this->payload($this->carts->forCustomer((int) $customer->getKey())));
    }

    /**
     * PATCH /cart/items/{item}
     */
    public function update(UpdateCartItemRequest $request, string $item): JsonResponse
    {
        $cart = $this->requireCart();

        $line = $this->carts->findItem($cart, $item);

        if ($line === null) {
            // 404 rather than 403: the line is not in THIS customer's basket, and
            // saying which of the two reasons applies would confirm it exists.
            throw new NotFoundHttpException;
        }

        $this->update->run($line, $request->toDto());

        return $this->ok($this->payload($this->carts->forCustomer($this->customerId())));
    }

    /**
     * DELETE /cart/items/{item}
     */
    public function destroy(string $item): JsonResponse
    {
        $cart = $this->requireCart();

        $line = $this->carts->findItem($cart, $item);

        if ($line === null) {
            throw new NotFoundHttpException;
        }

        $this->remove->run($line);

        return $this->ok($this->payload($this->carts->forCustomer($this->customerId())));
    }

    /**
     * Every response is THE WHOLE BASKET, not the line that changed.
     *
     * A cart's totals, its seller grouping and its availability flags all move
     * when any line does, so returning a fragment would make the client either
     * re-fetch or recompute — and recomputing a total client-side is how two
     * screens end up disagreeing about what something costs.
     */
    private function payload(?Cart $cart): CartResource
    {
        $priced = $cart === null
            ? ['lines' => [], 'groups' => [], 'items_total_minor' => 0, 'currency_code' => null, 'has_unsellable_lines' => false]
            : $this->pricing->price($cart);

        return new CartResource($priced);
    }

    private function requireCart(): Cart
    {
        $cart = $this->carts->forCustomer($this->customerId());

        if ($cart === null) {
            throw new NotFoundHttpException;
        }

        return $cart;
    }

    private function customer(): User
    {
        /** @var User $user */
        $user = current_actor();

        return $user;
    }

    private function customerId(): int
    {
        return (int) $this->customer()->getKey();
    }
}
