<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\DeleteCustomerAddressAction;
use App\Modules\Order\Application\Actions\SetDefaultAddressAction;
use App\Modules\Order\Application\Actions\UpdateCustomerAddressAction;
use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\Models\CustomerAddress;
use App\Modules\Order\Presentation\Requests\CustomerAddressRequest;
use App\Modules\Order\Presentation\Resources\CustomerAddressResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's address book (ADR-056, §4).
 *
 * EVERY LOOKUP IS BY UUID **AND** OWNER, through the repository — so "not yours"
 * and "does not exist" are the same 404. Distinguishing them would let anyone
 * confirm an address uuid is real, and an address is the most sensitive thing
 * this module stores: it is where a person lives.
 *
 * THAT IS WHY THERE IS NO ROUTE-MODEL BINDING HERE. Binding `{address}` by uuid
 * would resolve another customer's row and leave the ownership check to a policy
 * — one forgotten `authorize()` away from handing it over. Resolving through a
 * customer-scoped repository makes the leak unrepresentable rather than guarded.
 *
 * @see docs/modules/Order.md §2.2
 */
final class CustomerAddressController extends BaseController
{
    public function __construct(
        private readonly CustomerAddressRepositoryContract $addresses,
        private readonly CreateCustomerAddressAction $create,
        private readonly UpdateCustomerAddressAction $update,
        private readonly DeleteCustomerAddressAction $delete,
        private readonly SetDefaultAddressAction $setDefault,
    ) {}

    /**
     * GET /addresses
     */
    public function index(): JsonResponse
    {
        return $this->ok(
            CustomerAddressResource::collection($this->addresses->forCustomer($this->customerId())),
        );
    }

    /**
     * POST /addresses
     */
    public function store(CustomerAddressRequest $request): JsonResponse
    {
        $customer = $this->customer();

        $address = $this->create->run((int) $customer->getKey(), $customer->uuid, $request->toDto());

        return $this->created(new CustomerAddressResource($address->load('country')));
    }

    /**
     * PATCH /addresses/{address}
     */
    public function update(CustomerAddressRequest $request, string $address): JsonResponse
    {
        $model = $this->find($address);

        $this->update->run($model, $request->toDto());

        return $this->ok(new CustomerAddressResource($model->fresh()->load('country')));
    }

    /**
     * DELETE /addresses/{address}
     */
    public function destroy(string $address): JsonResponse
    {
        $this->delete->run($this->find($address));

        return $this->noContent();
    }

    /**
     * POST /addresses/{address}/default
     *
     * Both purposes by default — the common case is one address doing everything,
     * and asking a client to send two booleans for it is friction. A client that
     * wants only one sends the flag.
     */
    public function setDefault(string $address): JsonResponse
    {
        $model = $this->find($address);

        $forShipping = (bool) request()->boolean('shipping', true);
        $forBilling = (bool) request()->boolean('billing', true);

        $this->setDefault->run($model, $forShipping, $forBilling);

        return $this->ok(new CustomerAddressResource($model->fresh()->load('country')));
    }

    /**
     * BY UUID AND OWNER — see the class docblock on why this is not a bound model.
     */
    private function find(string $uuid): CustomerAddress
    {
        $address = $this->addresses->findForCustomer($uuid, $this->customerId());

        if ($address === null) {
            throw new NotFoundHttpException;
        }

        return $address;
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
