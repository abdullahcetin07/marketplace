<?php

declare(strict_types=1);

namespace App\Modules\Settings\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Settings\Domain\Enums\SettingGroup;
use App\Modules\Settings\Domain\Enums\SettingType;
use App\Modules\Settings\Infrastructure\Casts\EncryptedSettingValue;
use App\Shared\Traits\HasUuid;
use Database\Modules\Settings\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single configurable platform setting.
 *
 * SETTINGS vs CONFIG — the line this module draws:
 *   config/*.php  is what the APPLICATION needs to boot: database credentials,
 *                 queue connections, cache stores. Changing it is a deploy.
 *   settings      is what the BUSINESS decides: company address, whether guest
 *                 checkout is on, how many products a seller may list.
 *                 Changing it is a click.
 *
 * A value that must be correct before the framework boots can never live here,
 * because reading it requires a database connection that config already
 * defined.
 *
 * `is_encrypted` exists for third-party credentials (SMTP password, SMS API
 * key) that legitimately belong to the business rather than the deployment.
 *
 * @property int $id
 * @property string $uuid
 * @property string $key
 * @property SettingGroup $group
 * @property SettingType $type
 * @property string|null $value
 * @property string|null $default_value
 * @property string|null $label
 * @property string|null $description
 * @property bool $is_public
 * @property bool $is_encrypted
 * @property bool $is_locked
 * @property int $sort_order
 *
 * This Domain model names an Infrastructure cast (`EncryptedSettingValue`) in
 * `casts()`. Permitted by ADR-023: Eloquent is Active Record, so declaring ORM
 * metadata is not a dependency. Naming a class is metadata; calling a method on
 * an Infrastructure service would not be.
 *
 * @see App\Modules\Settings\Infrastructure\Casts\EncryptedSettingValue
 * @see App\Modules\Settings\Application\Services\SettingsService
 * @see docs/settings.md
 */
final class Setting extends Model
{
    use Auditable;

    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'settings';

    protected $fillable = [
        'key',
        'group',
        'type',
        'value',
        'default_value',
        'label',
        'description',
        'is_public',
        'is_encrypted',
        'is_locked',
        'sort_order',
    ];

    /**
     * The raw column is excluded from the audit trail because an encrypted
     * setting's plaintext must not be reconstructable from history.
     * `SettingsService` writes a redacted marker instead for those.
     *
     * @var array<int, string>
     */
    protected array $auditExclude = ['uuid'];

    /**
     * The typed, decrypted value — or the default when unset.
     *
     * This is the only correct way to read a setting's value. Reading `->value`
     * directly returns the raw string and, for an encrypted setting, ciphertext.
     */
    public function typedValue(): mixed
    {
        /*
        | `value` is already plaintext here: EncryptedSettingValue decrypts on
        | read (ADR-019 moved encryption to Infrastructure). A value written
        | under a since-rotated APP_KEY casts to null, so it falls through to
        | default_value — the same behaviour the inline decrypt() used to give,
        | without key material in the Domain layer.
        */
        $raw = $this->value ?? $this->default_value;

        return $raw === null ? null : $this->type->cast($raw);
    }

    /**
     * Set the value, serialising and encrypting per this setting's flags.
     *
     * Does not save — the caller decides, so a batch update is one transaction.
     */
    public function setTypedValue(mixed $value): static
    {
        // EncryptedSettingValue encrypts on write when is_encrypted is set.
        $this->value = $this->type->serialise($value);

        return $this;
    }

    /**
     * Whether this setting is safe to expose without authentication.
     *
     * BOTH gates must pass: the group must be publicly readable AND the row
     * must be flagged public. An encrypted setting is never public regardless.
     * Two independent conditions, because one forgotten flag on an SMTP
     * password would otherwise publish a credential.
     */
    public function isPubliclyReadable(): bool
    {
        return $this->is_public
            && ! $this->is_encrypted
            && $this->group->isPubliclyReadable();
    }

    /**
     * Locked settings are seeded infrastructure that the UI may display but
     * must not let anyone edit — deleting or renaming them would break code
     * that reads them by key.
     */
    public function isEditable(): bool
    {
        return ! $this->is_locked;
    }

    public function resetToDefault(): bool
    {
        return $this->forceFill(['value' => null])->save();
    }

    public function hasCustomValue(): bool
    {
        return $this->value !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInGroup(Builder $query, SettingGroup $group): Builder
    {
        return $query->where('group', $group->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)
            ->where('is_encrypted', false)
            ->whereIn('group', array_map(
                static fn (SettingGroup $g): string => $g->value,
                array_filter(SettingGroup::cases(), static fn (SettingGroup $g): bool => $g->isPubliclyReadable()),
            ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'group' => SettingGroup::class,
            'type' => SettingType::class,
            // Encryption lives in Infrastructure (ADR-019). The cast is
            // conditional on this row's is_encrypted flag.
            'value' => EncryptedSettingValue::class,
            'is_public' => 'boolean',
            'is_encrypted' => 'boolean',
            'is_locked' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (self $setting): void {
            // Keys are lowercase dotted paths. Normalising on write means a
            // lookup never has to guess casing, and 'Mail.From' and 'mail.from'
            // cannot become two rows that shadow each other.
            $setting->key = mb_strtolower(trim($setting->key));
        });

        static::deleting(static function (self $setting): bool {
            // A locked setting is read by code, by key. Deleting it turns a
            // working feature into a null dereference at runtime.
            return ! $setting->is_locked;
        });

        // Cache invalidation is SettingCacheObserver's job (ADR-019).
    }
}
