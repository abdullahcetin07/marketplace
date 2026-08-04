<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Localization\Domain\Models\Timezone;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Base factory for every actor type.
 *
 * The three concrete factories (AdminFactory, SellerFactory, CustomerFactory)
 * extend this and set $model. They are separate classes rather than states
 * because each actor type is a distinct model with its own global scope —
 * `Admin::factory()` must build an Admin, not a User with an admin `type`,
 * or the scope is bypassed and the test proves nothing.
 *
 * LOCALE FIELDS ARE NULL BY DEFAULT. They became foreign keys into the
 * Localization tables in Sprint 1, and eagerly creating four related rows for
 * every user would make the factory slow and would pollute tests with locale
 * data they did not ask for. Null means "use the platform default", which is
 * the behaviour User::preferredLocale() and preferredCurrency() implement.
 * Use ->localised() when a test needs them populated.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The plaintext every factory-built user is given. Tests log in with this.
     */
    public const string PASSWORD = 'password';

    /** @var class-string<User> */
    protected $model = User::class;

    /**
     * Hashing dominates factory runtime. One shared hash across all generated
     * users turns a 200-user seed from ~20 seconds into instant; the value is
     * meaningless outside tests.
     */
    private static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'type' => static::actorType(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$passwordHash ??= Hash::make(self::PASSWORD),
            'phone' => fake()->numerify('+90 5## ### ## ##'),
            'status' => Status::Active,
            'language_id' => null,
            'country_id' => null,
            'currency_id' => null,
            'timezone_id' => null,
            'login_count' => 0,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Populate the locale relations, creating the rows if they do not exist.
     *
     * firstOr* rather than factory(): a test that creates ten users must not
     * end up with ten Turkish language rows and a violated unique index.
     */
    public function localised(): static
    {
        return $this->state(fn (): array => [
            'language_id' => Language::query()->firstOrCreate(
                ['code' => 'tr'],
                ['locale' => 'tr-TR', 'name' => 'Turkish', 'native_name' => 'Türkçe',
                    'direction' => 'ltr', 'is_default' => true, 'is_active' => true,
                    'uuid' => (string) Str::uuid()],
            )->getKey(),
            'currency_id' => Currency::query()->firstOrCreate(
                ['code' => 'TRY'],
                ['name' => 'Turkish Lira', 'symbol' => '₺', 'symbol_position' => 'after',
                    'decimal_places' => 2, 'decimal_separator' => ',', 'thousands_separator' => '.',
                    'exchange_rate' => '1.0000000000', 'is_default' => true, 'is_active' => true,
                    'uuid' => (string) Str::uuid()],
            )->getKey(),
            'country_id' => Country::query()->firstOrCreate(
                ['iso2' => 'TR'],
                ['iso3' => 'TUR', 'name' => 'Türkiye', 'native_name' => 'Türkiye',
                    'phone_code' => '90', 'is_active' => true, 'uuid' => (string) Str::uuid()],
            )->getKey(),
            'timezone_id' => Timezone::query()->firstOrCreate(
                ['name' => 'Europe/Istanbul'],
                ['label' => 'İstanbul', 'offset_minutes' => 180, 'is_active' => true,
                    'uuid' => (string) Str::uuid()],
            )->getKey(),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => Status::Suspended]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => Status::Pending]);
    }

    /**
     * A user with no surname — a sole trader, or a single-name culture.
     *
     * `last_name` is nullable by decision (ADR-012), so there must be a
     * fixture that exercises the null branch of `display_name`.
     */
    public function withoutLastName(): static
    {
        return $this->state(fn (): array => ['last_name' => null]);
    }

    /**
     * Fixed name, for tests asserting on display formatting.
     */
    public function named(string $firstName, ?string $lastName = null): static
    {
        return $this->state(fn (): array => [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (): array => [
            // A VALID base32 secret. Google2FA rejects anything outside the
            // base32 alphabet (A-Z, 2-7), so Str::random() makes verifyKey()
            // throw InvalidCharactersException. The value itself is irrelevant.
            'two_factor_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
            // Recovery codes are stored HASHED in production. Hash::check() on a
            // strict Bcrypt hasher throws on a non-bcrypt string, so store real
            // bcrypt hashes — verification then iterates them without blowing up.
            'two_factor_recovery_codes' => json_encode(
                array_map(static fn (): string => Hash::make(Str::random(10)), range(1, 8)),
                JSON_THROW_ON_ERROR,
            ),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Assign a role by NAME, resolved through config. Never by id.
     */
    public function withRole(string $configKey): static
    {
        return $this->afterCreating(function (User $user) use ($configKey): void {
            $user->assignRole(config("marketplace.roles.{$configKey}"));
        });
    }

    protected static function actorType(): UserType
    {
        return UserType::Customer;
    }
}
