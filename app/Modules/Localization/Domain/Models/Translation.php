<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Models;

use App\Shared\Traits\HasUuid;
use Database\Modules\Localization\Factories\TranslationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single translated string, editable by administrators.
 *
 * WHY A TABLE AND NOT JUST lang/ FILES: copy changes are the single most
 * frequent request an operations team makes, and routing every one of them
 * through a developer and a deploy is a bottleneck that produces stale UI. The
 * file-based translations remain the authored default and ship with the code;
 * this table overlays edits on top of them.
 *
 * Resolution order (see DatabaseTranslationLoader):
 *   database override → lang/{locale}/{group}.php → key itself
 *
 * That order matters. Files stay the source of truth for what keys EXIST, so a
 * new key added by a developer works immediately with no database row, and a
 * mistaken override can be removed to fall back to the shipped copy.
 *
 * @property int $id
 * @property string $uuid
 * @property int $language_id
 * @property string $group      lang file name — 'errors', 'enums', '*' for JSON strings
 * @property string $key        dotted path within the group
 * @property string $value
 * @property bool $is_overridden  true when an admin edited a shipped string
 * @property-read Language $language
 */
final class Translation extends Model
{
    /** @use HasFactory<TranslationFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * Group used for Laravel's JSON-style translations (`__('Some string')`),
     * which have no file group of their own.
     */
    public const string JSON_GROUP = '*';

    protected $table = 'translations';

    protected $fillable = [
        'language_id',
        'group',
        'key',
        'value',
        'is_overridden',
    ];

    /**
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Full dotted reference, as a developer would write it: `errors.not_found`.
     */
    public function reference(): string
    {
        return $this->group === self::JSON_GROUP
            ? $this->key
            : $this->group.'.'.$this->key;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForLanguage(Builder $query, Language|int $language): Builder
    {
        return $query->where(
            'language_id',
            $language instanceof Language ? $language->getKey() : $language,
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    /**
     * Free-text search across key and value, for the translation manager UI.
     *
     * `ilike` is PostgreSQL's case-insensitive LIKE; on SQLite (tests) LIKE is
     * already case-insensitive for ASCII, so `like` is used there.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $term = '%'.$term.'%';

        return $query->where(
            fn (Builder $q): Builder => $q->where('key', $operator, $term)
                ->orWhere('value', $operator, $term),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_overridden' => 'boolean',
        ];
    }

    /*
    | Cache invalidation for (language, group) is LocalizationCacheObserver's
    | job (ADR-019) — an edit in the admin panel is still visible on the next
    | request, but the cache() call lives in Infrastructure.
    */
}
