<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Models;

use App\Modules\Localization\Domain\Enums\TextDirection;
use App\Shared\Traits\HasUuid;
use Database\Modules\Localization\Factories\LanguageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A locale the platform can be served in.
 *
 * WAS AN ENUM IN SPRINT 0. Promoted to a table so the platform supports
 * unlimited languages and an administrator can add one without a deploy.
 * See docs/001_Architecture.md §"Enums vs lookup tables".
 *
 * `code` is the Laravel locale and the URL segment (`tr`). `locale` is the
 * BCP-47 tag used for `<html lang>`, hreflang and Accept-Language negotiation
 * (`tr-TR`). Keeping both means a regional variant can be added later without
 * changing every route.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $locale
 * @property string $name English exonym, for admin lists
 * @property string $native_name endonym — always render a language in itself
 * @property TextDirection $direction
 * @property string|null $flag
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
final class Language extends Model
{
    /** @use HasFactory<LanguageFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'languages';

    protected $fillable = [
        'code',
        'locale',
        'name',
        'native_name',
        'direction',
        'flag',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /*
    |--------------------------------------------------------------------------
    | Reads live in the repository, not here
    |--------------------------------------------------------------------------
    |
    | ADR-019 forbids cache() in the Domain layer. The cached finders that used
    | to sit here — default(), fallback(), findByCode(), enabled(), current() —
    | are now LanguageRepositoryContract, implemented in Infrastructure.
    |
    |   app(LanguageRepositoryContract::class)->default();
    |
    | This model keeps relationships, scopes, casts and the invariants below.
    */

    /**
     * @return HasMany<Translation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    public function isRtl(): bool
    {
        return $this->direction->isRtl();
    }

    /**
     * Make this the platform default. Wrapped so the caller cannot forget to
     * demote the previous one.
     */
    public function makeDefault(): bool
    {
        return $this->forceFill([
            'is_default' => true,
            'is_active' => true, // the default can never be disabled
        ])->save();
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TextDirection::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $language): void {
            // Exactly one default, and it must be active.
            if ($language->is_default && $language->isDirty('is_default')) {
                self::query()
                    ->where('is_default', true)
                    ->whereKeyNot($language->getKey() ?? 0)
                    ->update(['is_default' => false]);
            }
        });

        self::deleting(static function (self $language): void {
            // Deleting the default locale would leave the application with no
            // locale to fall back to on the very next request.
            if ($language->is_default) {
                throw new RuntimeException(
                    'The default language cannot be deleted. Promote another language first.',
                );
            }
        });

        // Cache invalidation is LocalizationCacheObserver's job (ADR-019).
    }
}
