<?php

declare(strict_types=1);

namespace Database\Modules\Shipping\Factories;

use App\Modules\Shipping\Domain\Models\CargoCompany;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CargoCompany>
 */
final class CargoCompanyFactory extends Factory
{
    protected $model = CargoCompany::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'kargo-'.Str::lower(Str::random(6));

        return [
            'code' => $code,
            'name' => 'Test Kargo',
            // A template with the real token, so anything asserting a tracking
            // link exercises the substitution rather than a literal.
            'tracking_url_template' => 'https://kargo.example.test/takip/'.CargoCompany::TRACKING_TOKEN,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A carrier with no public tracking page — the case where a number must be
     * rendered as text rather than as a link.
     */
    public function withoutTracking(): self
    {
        return $this->state(fn (): array => ['tracking_url_template' => null]);
    }
}
