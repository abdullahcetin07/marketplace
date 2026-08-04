<?php

declare(strict_types=1);

namespace App\Modules\Media;

use App\Modules\Media\Application\Services\MediaService;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Media module wiring.
 *
 * INFRASTRUCTURE ONLY in Sprint 1. The `media` table, the S3 disks, the
 * validation gate, the optimisation and deletion jobs all exist and are
 * exercised. What does NOT exist is any model attaching media to it — product
 * images arrive with the Catalogue module.
 *
 * The table itself is created by the Sprint 0 core migration, because
 * App\Shared\Traits\HasMedia is part of the foundation and is inert without it.
 *
 * @see docs/media.md
 */
final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaService::class);

        // Sellers upload their own store and product media; the ownership
        // check lives on the policy of whatever model owns the collection.
        PermissionRegistry::resource('media', [UserType::Admin, UserType::Seller]);
        PermissionRegistry::ability('media.upload', [UserType::Admin, UserType::Seller]);
    }

    public function boot(): void
    {
        // No migrations of its own — `media` ships with the core schema.
        $this->loadMigrationsFrom(database_path('Modules/Media/migrations'));
    }
}
