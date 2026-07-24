<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Shared\Enums\UserType;

/**
 * @extends UserFactory<Customer>
 */
final class CustomerFactory extends UserFactory
{
    /** @var class-string<Customer> */
    protected $model = Customer::class;

    public function registered(): static
    {
        return $this->withRole('customer');
    }

    protected static function actorType(): UserType
    {
        return UserType::Customer;
    }
}
