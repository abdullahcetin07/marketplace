<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Domain\DTOs\RegisterUserDTO;
use App\Modules\Identity\Domain\Events\UserCreated;
use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Events\Registered;

/**
 * Create an account.
 *
 * Runs in a transaction: the user row, the role assignment and the locale
 * resolution must all land or none of them. A user created without their role
 * holds no permissions and looks broken in a way that is tedious to diagnose.
 *
 * NEW ACCOUNTS ARE NOT ACTIVE. Customers start Active (they have nothing to
 * abuse yet); sellers start Pending and require approval. That asymmetry is
 * the whole reason a marketplace has an onboarding flow.
 *
 * ADMINS CANNOT BE REGISTERED. Self-service admin signup is a critical
 * vulnerability; administrators are provisioned by
 * `php artisan marketplace:create-admin`.
 */
final class RegisterUserAction extends BaseAction
{
    /**
     * Locale lookups arrive through their CONTRACTS (ADR-019) — the caching
     * lives in Infrastructure, and this action stays unit-testable against
     * fakes.
     */
    public function __construct(
        private readonly LanguageRepositoryContract $languages,
        private readonly CountryRepositoryContract $countries,
        private readonly CurrencyRepositoryContract $currencies,
    ) {}

    public function handle(mixed ...$arguments): User
    {
        /** @var RegisterUserDTO $data */
        $data = $arguments[0];

        /** @var class-string<User> $model */
        $model = $data->type->model();

        $user = $model::query()->create([
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'email' => $data->normalisedEmail(),
            'password' => $data->password,
            'phone' => $data->phone,
            'status' => $this->initialStatusFor($data->type),
            'language_id' => $this->languageId($data->languageCode),
            'country_id' => $this->countryId($data->countryCode),
            'currency_id' => $this->currencyId($data->currencyCode),
        ]);

        $user->assignRole(config("marketplace.roles.{$data->roleKey()}"));

        return $user;
    }

    protected function before(mixed ...$arguments): void
    {
        /** @var RegisterUserDTO $data */
        $data = $arguments[0];

        if ($data->type === UserType::Admin) {
            throw new \DomainException(
                'Administrators cannot be self-registered. Use marketplace:create-admin.',
            );
        }
    }

    /**
     * After commit: fire the verification email and announce the account.
     *
     * Laravel's own Registered event drives the verification notification;
     * UserCreated is the platform's domain event that other modules consume.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var User $result */
        event(new Registered($result));

        UserCreated::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->type,
            $result->email,
        );
    }

    /**
     * A seller must be approved before they can trade; a customer has nothing
     * to approve. @see App\Shared\Enums\Status
     */
    private function initialStatusFor(UserType $type): Status
    {
        return $type === UserType::Seller ? Status::Pending : Status::Active;
    }

    private function languageId(?string $code): ?int
    {
        return ($code === null ? $this->languages->default() : $this->languages->findByCode($code))?->getKey();
    }

    private function countryId(?string $iso2): ?int
    {
        return ($iso2 === null ? $this->countries->default() : $this->countries->findByIso2($iso2))?->getKey();
    }

    private function currencyId(?string $code): ?int
    {
        return ($code === null ? $this->currencies->default() : $this->currencies->findByCode($code))?->getKey();
    }
}
