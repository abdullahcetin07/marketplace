<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Admin;
use App\Shared\Enums\UserType;

/**
 * @extends UserFactory<Admin>
 */
final class AdminFactory extends UserFactory
{
    /** @var class-string<Admin> */
    protected $model = Admin::class;

    /**
     * A platform super-admin. Use sparingly in tests: BasePolicy::before()
     * short-circuits for this role, so a test using it proves nothing about
     * the permissions under test.
     */
    public function superAdmin(): static
    {
        return $this->withRole('super_admin');
    }

    /**
     * Broad operational access WITHOUT the super-admin bypass. This is the
     * right fixture for most admin tests — it actually exercises the
     * permission checks.
     */
    public function admin(): static
    {
        return $this->withRole('admin');
    }

    public function editor(): static
    {
        return $this->withRole('editor');
    }

    public function categoryManager(): static
    {
        return $this->withRole('category_manager');
    }

    public function support(): static
    {
        return $this->withRole('support');
    }

    public function finance(): static
    {
        return $this->withRole('finance');
    }

    protected static function actorType(): UserType
    {
        return UserType::Admin;
    }
}
