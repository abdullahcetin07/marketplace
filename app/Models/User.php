<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Activity\Domain\Models\ActivityEntry;
use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Identity\Domain\Models\LoginAttempt;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Identity\Domain\Models\UserSession;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Localization\Domain\Models\Timezone;
use App\Modules\Notification\Domain\Models\NotificationPreference;
use App\Shared\Enums\NotificationType;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use App\Shared\Traits\HasStatus;
use App\Shared\Traits\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use LogicException;
use Spatie\Permission\Traits\HasRoles;

/**
 * The base actor. Admins, sellers and customers are all rows in `users`,
 * discriminated by the `type` column and represented by the subclasses in this
 * namespace.
 *
 * WHY SINGLE-TABLE INHERITANCE RATHER THAN THREE TABLES:
 * Every actor type shares the same identity surface — credentials, email
 * verification, 2FA, password resets, sessions, locale, soft deletes. Three
 * tables means three copies of all of it, three password-reset flows, and a
 * genuine problem the first time one human is both a seller and a customer.
 * One table with three guards keeps identity single while keeping authorisation
 * strictly separated: a seller session can never satisfy the admin guard,
 * because the guard's provider is scoped to `type = 'seller'`.
 *
 * WHY THIS CLASS MAY IMPORT MODULES (and modules may import it):
 * User is the aggregate every module hangs relations off — sessions, audit
 * entries, activity, notifications. Putting it inside one module would make the
 * other six depend on that module; putting the relations elsewhere would make
 * `$user->sessions` unavailable, which is what Sprint 1 asks for explicitly.
 * So `app/Models` sits ABOVE the module layer in the dependency graph, like
 * `app/Providers` does. The rule that still holds absolutely: modules must not
 * import each other. Cost: a change to a module's model namespace touches this
 * file. Accepted, and asserted by tests/Architecture/LayeringTest.php.
 *
 * @property int $id
 * @property string $uuid
 * @property UserType $type
 * @property string $first_name
 * @property string|null $last_name
 * @property-read string $display_name  computed — never a column (ADR-012)
 * @property string $email
 * @property string $password
 * @property Status $status
 * @property string|null $phone
 * @property string|null $avatar_url
 * @property int|null $language_id
 * @property int|null $country_id
 * @property int|null $currency_id
 * @property int|null $timezone_id
 * @property int $login_count
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $password_changed_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property-read Language|null $language
 * @property-read Country|null $country
 * @property-read Currency|null $currency
 * @property-read Timezone|null $timezone
 *
 * @see docs/authentication.md
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    // The User is an auditable aggregate: every account change — admin or
    // self-service — leaves an immutable before/after record. Sensitive columns
    // (password, remember_token, two_factor_secret, two_factor_recovery_codes)
    // are on the trait's global exclusion list and never reach the trail.
    use Auditable;
    use HasRoles;
    use HasStatus;
    use HasUuid;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'status',
        'language_id',
        'country_id',
        'currency_id',
        'timezone_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Computed attributes included in array and JSON output.
     *
     * `display_name` is here so any serialisation carries it, not only the API
     * resource that names it explicitly. It is NOT in `$fillable` — appending
     * exposes it for reading and does nothing for writing.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'display_name',
    ];

    /**
     * Scope every query on a subclass to its own actor type.
     *
     * This is the mechanism that keeps the three guards genuinely independent:
     * Admin::find($id) can never return a seller, and the seller guard's user
     * provider (which resolves Seller::class) can never authenticate an admin
     * even if the attacker knows a valid admin password.
     */
    protected static function booted(): void
    {
        $type = static::actorType();

        if ($type !== null) {
            static::addGlobalScope(
                'actor_type',
                static fn (Builder $query): Builder => $query->where('type', $type->value),
            );

            static::creating(static function (self $model) use ($type): void {
                $model->type = $type;
            });
        }
    }

    /**
     * The actor type this class represents. Null on the base User, which is
     * deliberately unscoped so relations like `creator()` can resolve any actor.
     */
    public static function actorType(): ?UserType
    {
        return null;
    }

    // ---------------------------------------------------------------------
    // Authorisation
    // ---------------------------------------------------------------------

    /**
     * Guard this user authenticates against.
     *
     * Spatie reads this to scope role and permission lookups, which is why a
     * seller's `store.update` and an admin's `store.update` are separate
     * records that cannot be confused for one another.
     */
    public function guardName(): string
    {
        return ($this->type ?? UserType::Customer)->guard();
    }

    /**
     * Spatie inspects `$model->guard_name` in some code paths; route it to the
     * same source of truth so the two can never disagree.
     */
    public function getGuardNameAttribute(): string
    {
        return $this->guardName();
    }

    public function getDefaultGuardName(): string
    {
        return $this->guardName();
    }

    /**
     * Platform super-admin bypass, consumed by BasePolicy::before().
     *
     * Deliberately a named role check rather than a permission: this is the
     * one place a role is meaningful in itself, because "can do everything"
     * cannot be expressed as a permission without enumerating all of them.
     */
    public function isSuperAdmin(): bool
    {
        return $this->type === UserType::Admin
            && $this->hasRole(config('marketplace.roles.super_admin'), UserType::Admin->guard());
    }

    public function isAdmin(): bool
    {
        return $this->type === UserType::Admin;
    }

    public function isSeller(): bool
    {
        return $this->type === UserType::Seller;
    }

    public function isCustomer(): bool
    {
        return $this->type === UserType::Customer;
    }

    // ---------------------------------------------------------------------
    // Naming
    // ---------------------------------------------------------------------

    /**
     * The name to show a human — `$user->display_name`.
     *
     * A COMPUTED ATTRIBUTE (ADR-012). Its four guarantees:
     *
     *  1. **Never persisted.** There is no `display_name` or `full_name`
     *     column and there must never be one — a denormalised copy is a second
     *     source of truth that drifts the first time one side is written
     *     alone.
     *  2. **Never writable.** The setter throws. Writing it would either
     *     silently do nothing or attempt an INSERT against a column that does
     *     not exist; both are worse than failing loudly at the assignment.
     *  3. **Never mass assignable.** Absent from `$fillable`, so `fill()` and
     *     `create()` cannot reach it.
     *  4. **Always computed dynamically.** Derived on every read, so it can
     *     never disagree with `first_name` / `last_name`.
     *
     * `last_name` is nullable, so this collapses cleanly to just the given
     * name for sole traders and for cultures with a single name.
     *
     * An accessor rather than a plain method so it works unchanged in Blade,
     * in Filament table columns and in API resources, none of which call
     * methods on a model.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim($this->first_name.' '.($this->last_name ?? '')),
            // A full closure, not an arrow fn: an arrow fn implicitly returns
            // its expression, which conflicts with the `never` return type.
            set: static function (): never {
                throw new LogicException(
                    'display_name is computed from first_name and last_name (ADR-012) '
                    .'and cannot be written. Set those instead.',
                );
            },
        );
    }

    /**
     * Initials for an avatar placeholder — "AY" for Ayşe Yılmaz, "M" for
     * Madonna.
     */
    public function initials(): string
    {
        $first = mb_substr($this->first_name, 0, 1);
        $last = $this->last_name === null ? '' : mb_substr($this->last_name, 0, 1);

        return mb_strtoupper($first.$last);
    }

    // ---------------------------------------------------------------------
    // Localisation preferences
    // ---------------------------------------------------------------------

    /**
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<Timezone, $this>
     */
    public function timezone(): BelongsTo
    {
        return $this->belongsTo(Timezone::class);
    }

    /**
     * Locale for notifications and mail, falling back to the platform default.
     *
     * Implements Laravel's HasLocalePreference contract by name, so queued
     * notifications render in the recipient's language rather than the
     * language of whoever triggered them.
     */
    public function preferredLocale(): string
    {
        return $this->language?->code
            ?? app(LanguageRepositoryContract::class)->default()->code;
    }

    /**
     * IANA timezone name for rendering timestamps to this user.
     */
    public function timezoneName(): string
    {
        return $this->timezone?->name ?? (string) config('app.timezone');
    }

    public function preferredCurrency(): Currency
    {
        return $this->currency ?? app(CurrencyRepositoryContract::class)->default();
    }

    // ---------------------------------------------------------------------
    // Identity module relations
    // ---------------------------------------------------------------------

    /**
     * Active and historical sessions. @see docs/authentication.md §Sessions
     *
     * @return HasMany<UserSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * Every login attempt, successful or not. Failures are retained too —
     * they are the primary signal of credential stuffing against an account.
     *
     * @return HasMany<LoginAttempt, $this>
     */
    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class);
    }

    // ---------------------------------------------------------------------
    // Audit / Activity / Notification relations
    // ---------------------------------------------------------------------

    /**
     * Field-level changes this user CAUSED, across every model.
     *
     * @return HasMany<AuditEntry, $this>
     */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class, 'causer_id')
            ->where('causer_type', self::class);
    }

    /**
     * Things this user DID — logged in, changed a password, updated a profile.
     * Distinct from audit entries, which record what changed on a record.
     *
     * @return HasMany<ActivityEntry, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityEntry::class);
    }

    /**
     * Unread in-app notifications, newest first.
     *
     * @return HasMany<DatabaseNotification, $this>
     */
    public function unreadNotifications(): HasMany
    {
        return $this->notifications()->whereNull('read_at');
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * Consulted by BaseNotification::shouldSend().
     *
     * Deny-list semantics: no row means "send it". Database notifications and
     * security alerts bypass this entirely — a user must not be able to mute
     * the message telling them their password changed.
     */
    public function hasOptedOutOf(NotificationType $channel, ?string $notificationClass = null): bool
    {
        return NotificationPreference::hasOptedOut($this, $channel, $notificationClass);
    }

    // ---------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------

    /**
     * Send OUR verification notification, not Laravel's default.
     *
     * The framework's `Registered` listener calls this method; overriding it is
     * the blessed extension point. The default builds a BACKEND signed URL —
     * ours redirects through the frontend per ADR-025, keeping the backend
     * frontend-agnostic. One override means one code path for both the
     * registration email and a resend.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Modules\Identity\Infrastructure\Notifications\VerifyEmailNotification);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether 2FA is mandatory for this actor. Reads the rollout switch rather
     * than hard-coding the policy, so enabling it for admins is a config
     * change. @see config/marketplace.php
     */
    public function requiresTwoFactor(): bool
    {
        if (! config('marketplace.security.two_factor.enabled')) {
            return false;
        }

        return in_array(
            $this->type?->value,
            (array) config('marketplace.security.two_factor.enforced_for', []),
            true,
        );
    }

    /**
     * Whether this account may sign in at all, independent of credentials.
     * Checked by the login flow so a suspended seller cannot authenticate.
     */
    public function canAuthenticate(): bool
    {
        return $this->status === Status::Active && $this->deleted_at === null;
    }

    /**
     * Gate access to a Filament panel.
     *
     * Kept separate from Filament's canAccessPanel(Panel) so the rule can be
     * asserted in tests and middleware without constructing a Panel object;
     * the subclasses delegate their Filament hook to this.
     */
    public function canAccessPanelId(string $panelId): bool
    {
        return $this->canAuthenticate() && $this->type->panel() === $panelId;
    }

    /**
     * saveQuietly: recording a login must not fire model events, or every
     * sign-in writes an "updated" audit entry and drowns the real ones.
     */
    public function recordLogin(?string $ip): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
            'login_count' => $this->login_count + 1,
        ])->saveQuietly();
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, UserType $type): Builder
    {
        return $query->withoutGlobalScope('actor_type')->where('type', $type->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithTwoFactor(Builder $query): Builder
    {
        return $query->whereNotNull('two_factor_confirmed_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'status' => Status::class,
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'login_count' => 'integer',
            'password' => 'hashed',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_secret' => 'encrypted',
        ];
    }
}
