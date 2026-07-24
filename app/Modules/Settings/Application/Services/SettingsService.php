<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application\Services;

use App\Modules\Settings\Domain\Enums\SettingGroup;
use App\Modules\Settings\Domain\Enums\SettingType;
use App\Modules\Settings\Domain\Events\SettingUpdated;
use App\Modules\Settings\Domain\Models\Setting;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Typed, cached access to platform settings.
 *
 * CACHING STRATEGY — the whole table, one key:
 * Settings are read many times per request (`settings('company.name')` in a
 * layout, in an email, in a policy) and written a handful of times per month.
 * Caching per key would mean dozens of cache round-trips per request and a
 * partial-invalidation problem; caching the whole table is one round-trip, one
 * invalidation, and the payload is a few kilobytes.
 *
 *     settings('company.name')                  via the global helper
 *     app(SettingsService::class)->get('...')   injected
 *
 * FAILURE MODE, DELIBERATE: if the table is missing or the database is
 * unreachable, `get()` returns the caller's default rather than throwing.
 * Settings decorate behaviour; they should not be able to take the platform
 * down, and this keeps `artisan migrate` working on an empty database.
 *
 * @see App\Modules\Settings\Domain\Models\Setting
 * @see docs/settings.md
 */
final class SettingsService
{
    private const string CACHE_KEY = 'settings:all';

    private const int CACHE_TTL = 86400;

    /**
     * Per-request memo on top of the cache — a layout may read the same key
     * a dozen times.
     *
     * @var array<string, mixed>|null
     */
    private ?array $resolved = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $key = mb_strtolower(trim($key));

        return $this->all()[$key] ?? $default;
    }

    /**
     * Typed accessors. They exist so call sites read as intent rather than as
     * casting, and so a mistyped setting fails at the boundary instead of
     * halfway through a calculation.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * @param  array<mixed>  $default
     * @return array<mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists(mb_strtolower(trim($key)), $this->all());
    }

    /**
     * Every setting as key => typed value.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        try {
            /** @var array<string, mixed> $values */
            $values = cache()->remember(
                self::CACHE_KEY,
                self::CACHE_TTL,
                static fn (): array => Setting::query()
                    ->get()
                    ->mapWithKeys(static fn (Setting $s): array => [$s->key => $s->typedValue()])
                    ->all(),
            );

            return $this->resolved = $values;
        } catch (Throwable) {
            // @see class docblock — settings must not be able to break boot.
            return $this->resolved = [];
        }
    }

    /**
     * Settings safe to serve to an unauthenticated client.
     *
     * Reads from the database rather than the `all()` cache because the public
     * subset is a different projection and must apply BOTH gates
     * (group + row flag). Cached separately.
     *
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        /** @var array<string, mixed> $values */
        $values = cache()->remember(
            self::CACHE_KEY.':public',
            self::CACHE_TTL,
            static fn (): array => Setting::query()
                ->public()
                ->get()
                ->filter(static fn (Setting $s): bool => $s->isPubliclyReadable())
                ->mapWithKeys(static fn (Setting $s): array => [$s->key => $s->typedValue()])
                ->all(),
        );

        return $values;
    }

    /**
     * @return Collection<int, Setting>
     */
    public function group(SettingGroup $group): Collection
    {
        return Setting::query()
            ->inGroup($group)
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();
    }

    /**
     * Write a value.
     *
     * Returns false for a locked setting rather than throwing, so a bulk
     * update of a settings form can report which fields were refused without
     * aborting the rest.
     */
    public function set(string $key, mixed $value): bool
    {
        $setting = Setting::query()->where('key', mb_strtolower(trim($key)))->first();

        if ($setting === null || ! $setting->isEditable()) {
            return false;
        }

        $previous = $setting->typedValue();

        $setting->setTypedValue($value)->save();

        $this->forget();

        /*
        | The event carries a REDACTED value for encrypted settings. An SMTP
        | password must not travel through the audit log and the queue in
        | plaintext just because it changed.
        */
        SettingUpdated::dispatch(
            $setting->key,
            $setting->group,
            $setting->is_encrypted ? '[redacted]' : $previous,
            $setting->is_encrypted ? '[redacted]' : $setting->typedValue(),
            current_actor()?->getKey(),
        );

        return true;
    }

    /**
     * Write several settings. Each is attempted independently; the return value
     * lists the keys that were refused (locked or unknown).
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    public function setMany(array $values): array
    {
        $refused = [];

        foreach ($values as $key => $value) {
            if (! $this->set($key, $value)) {
                $refused[] = $key;
            }
        }

        return $refused;
    }

    /**
     * Create a setting if it does not exist. Used by module installers so a
     * new module's settings appear without a hand-written seeder edit.
     */
    public function register(
        string $key,
        SettingGroup $group,
        SettingType $type,
        mixed $default = null,
        ?string $label = null,
        bool $isPublic = false,
        bool $isEncrypted = false,
        bool $isLocked = false,
    ): Setting {
        $setting = Setting::query()->firstOrNew(['key' => mb_strtolower(trim($key))]);

        // Only ever fills metadata — never overwrites a value an operator set.
        $setting->fill([
            'group' => $group,
            'type' => $type,
            'default_value' => $type->serialise($default),
            'label' => $label ?? $key,
            'is_public' => $isPublic,
            'is_encrypted' => $isEncrypted,
            'is_locked' => $isLocked,
        ])->save();

        $this->forget();

        return $setting;
    }

    public function forget(): void
    {
        $this->resolved = null;
        cache()->forget(self::CACHE_KEY);
        cache()->forget(self::CACHE_KEY.':public');
    }
}
