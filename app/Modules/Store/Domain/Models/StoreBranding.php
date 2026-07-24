<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Models;

use App\Shared\Traits\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;

/**
 * A storefront's visual branding (§2.3).
 *
 * One row per store (HasOne). Logo, banner and favicon are single-file media
 * collections on the PUBLIC disk — a shopper's browser must load them, unlike
 * the organization's private documents. Theme fields (`primary_color`,
 * `accent_color`, a named `theme` preset) are plain columns.
 *
 * @property int $id
 * @property int $store_id
 * @property string|null $primary_color
 * @property string|null $accent_color
 * @property string|null $theme
 *
 * @see docs/modules/Store.md §2.3
 */
final class StoreBranding extends Model implements HasMediaContract
{
    use HasMedia;

    protected $table = 'store_branding';

    protected $fillable = [
        'store_id',
        'primary_color',
        'accent_color',
        'theme',
    ];

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * The three storefront assets, each a single public-disk image.
     *
     * This REPLACES the trait's default `images`/`documents` collections rather
     * than extending them — branding needs neither, and `parent::` cannot reach
     * a trait method used by this same class (the model extends Eloquent Model
     * directly), so calling it would fatal. Registering only what branding uses
     * is both correct and leaner.
     */
    public function registerMediaCollections(): void
    {
        foreach (['logo', 'banner', 'favicon'] as $collection) {
            $this->addMediaCollection($collection)
                ->useDisk(config('marketplace.media.public_disk'))
                ->acceptsMimeTypes(self::acceptedImageMimeTypes())
                ->singleFile();
        }
    }
}
