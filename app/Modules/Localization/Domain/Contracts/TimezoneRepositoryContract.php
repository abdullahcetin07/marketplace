<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Contracts;

use App\Modules\Localization\Domain\Models\Timezone;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read port for timezones.
 *
 * @see App\Modules\Localization\Infrastructure\Repositories\TimezoneRepository
 */
interface TimezoneRepositoryContract
{
    public function default(): ?Timezone;

    public function findByName(string $name): ?Timezone;

    /**
     * @return Collection<int, Timezone>
     */
    public function active(): Collection;

    public function flush(?Timezone $timezone = null): void;
}
