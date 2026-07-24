<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Localization\Application\Services\LocalizationService;
use App\Modules\Localization\Presentation\Resources\CountryResource;
use App\Modules\Localization\Presentation\Resources\TimezoneResource;
use Illuminate\Http\JsonResponse;

/**
 * Public locale data for the storefront's bootstrap.
 *
 * UNAUTHENTICATED BY DESIGN — the storefront needs the language switcher and
 * currency formatting rules before anyone signs in. Everything served here is
 * already public on the rendered page.
 *
 * One endpoint rather than four, because a client needs all of it at once and
 * four round-trips on first paint is the difference between a fast site and a
 * slow one. The payload is cached whole.
 */
final class LocalizationController extends BaseController
{
    public function __construct(private readonly LocalizationService $localization) {}

    /**
     * GET /api/v1/localization
     */
    public function index(): JsonResponse
    {
        return $this->ok($this->localization->bootstrapPayload());
    }

    /**
     * GET /api/v1/localization/countries
     *
     * Separate because it is large and only the checkout and address forms
     * need it — putting it in the bootstrap payload would slow every page load
     * for a list most visitors never see.
     */
    public function countries(): JsonResponse
    {
        // API Resource, never a raw map — 005_API_Standards §18.
        return $this->ok(CountryResource::collection($this->localization->countries()));
    }

    /**
     * GET /api/v1/localization/timezones
     */
    public function timezones(): JsonResponse
    {
        return $this->ok(TimezoneResource::collection($this->localization->timezones()));
    }
}
