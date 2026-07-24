<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Models\User;
use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The three principal kinds of authenticated actor.
 *
 * This enum is the hinge of the authentication design: it is simultaneously
 * the `users.type` discriminator column, the auth guard name, and the Spatie
 * `guard_name` that roles and permissions are scoped to. Keeping all three in
 * one place is what makes it impossible for them to drift apart.
 *
 * @see config/auth.php
 * @see docs/authentication.md
 */
enum UserType: string
{
    use HasEnumHelpers;

    case Admin = 'admin';
    case Seller = 'seller';
    case Customer = 'customer';

    /**
     * Resolve the type that a guard name belongs to.
     */
    public static function fromGuard(string $guard): ?self
    {
        return self::tryFrom($guard);
    }

    /**
     * The auth guard backing this actor type.
     *
     * Guard name === enum value by design. Do not introduce a mapping here;
     * if they ever diverge, config/auth.php and the Spatie guard_name column
     * silently disagree and permission checks start failing open.
     */
    public function guard(): string
    {
        return $this->value;
    }

    /**
     * Concrete single-table-inheritance model for this actor type.
     *
     * @return class-string<User>
     */
    public function model(): string
    {
        return match ($this) {
            self::Admin => Admin::class,
            self::Seller => Seller::class,
            self::Customer => Customer::class,
        };
    }

    /**
     * Filament panel id, or null when the actor has no back-office panel.
     * Customers are served exclusively by the Next.js storefront over the API.
     */
    public function panel(): ?string
    {
        return match ($this) {
            self::Admin => 'admin',
            self::Seller => 'seller',
            self::Customer => null,
        };
    }

    /**
     * Where a successful login lands.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin => '/admin',
            self::Seller => '/seller',
            self::Customer => (string) config('marketplace.frontend_url'),
        };
    }

    public function hasPanel(): bool
    {
        return $this->panel() !== null;
    }

    /**
     * Staff actors get stricter password rules and mandatory 2FA once the
     * policy is switched on. @see App\Shared\Rules\StrongPassword
     */
    public function isStaff(): bool
    {
        return $this === self::Admin;
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Seller => 'warning',
            self::Customer => 'info',
        };
    }
}
