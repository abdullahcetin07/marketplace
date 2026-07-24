<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Observers;

use App\Modules\Settings\Domain\Models\Setting;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Flushes the settings cache when a setting changes.
 *
 * ADR-019 moved this out of `Setting::booted()`, which called `cache()` from
 * the Domain layer.
 *
 * Coarse on purpose — the whole settings payload is one cache entry, because
 * settings are read constantly and written rarely. Rebuilding costs one query
 * and removes any chance of a stale key.
 *
 * Registered in SettingsServiceProvider.
 *
 * @see App\Modules\Settings\Application\Services\SettingsService
 * @see docs/settings.md
 */
final class SettingCacheObserver
{
    private const string KEY = 'settings:all';

    public function __construct(private readonly CacheRepository $cache) {}

    public function saved(Setting $setting): void
    {
        $this->flush();
    }

    public function deleted(Setting $setting): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        $this->cache->forget(self::KEY);
        $this->cache->forget(self::KEY.':public');
    }
}
