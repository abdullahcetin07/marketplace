<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Seller;
use App\Shared\Enums\UserType;

/**
 * @extends UserFactory<Seller>
 */
final class SellerFactory extends UserFactory
{
    /** @var class-string<Seller> */
    protected $model = Seller::class;

    public function owner(): static
    {
        return $this->withRole('seller');
    }

    public function employee(): static
    {
        return $this->withRole('seller_employee');
    }

    protected static function actorType(): UserType
    {
        return UserType::Seller;
    }
}
