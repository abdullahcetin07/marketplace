<?php

declare(strict_types=1);

namespace App\Core\Presentation\Middleware;

use App\Modules\Localization\Application\Services\LocalizationService;
use App\Modules\Localization\Domain\Models\Language;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the language for the request and applies it.
 *
 * Precedence, highest first:
 *   1. `?lang=` query parameter        — explicit, for share links and testing
 *   2. `X-Language` header             — what the Next.js storefront sends
 *   3. the authenticated user's saved preference
 *   4. Accept-Language negotiation
 *   5. the platform default
 *
 * WHY THIS ORDER: an explicit choice must beat a stored preference, or a user
 * following a Turkish share link while their account is set to English gets a
 * page that contradicts the link they clicked. Conversely, a stored preference
 * must beat Accept-Language, or a Turkish speaker on an English-configured
 * laptop can never make the choice stick.
 *
 * Only enabled languages are honoured at every step — a disabled locale is not
 * reachable by guessing its code.
 *
 * @see App\Modules\Localization\Application\Services\LocalizationService
 * @see docs/localization.md
 */
final class SetLocale
{
    public const string HEADER = 'X-Language';

    public function __construct(private readonly LocalizationService $localization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $language = $this->resolve($request);

        $this->localization->apply($language);

        /** @var Response $response */
        $response = $next($request);

        // Tell the client (and any cache in front of it) which locale it got.
        $response->headers->set('Content-Language', $language->locale);
        $response->headers->set(self::HEADER, $language->code);

        // Without Vary, a shared cache would serve a Turkish page to an
        // English speaker whose only difference was the header.
        $response->setVary(['Accept-Language', self::HEADER], false);

        return $response;
    }

    private function resolve(Request $request): Language
    {
        $explicit = $request->query('lang') ?? $request->header(self::HEADER);

        if (is_string($explicit) && $explicit !== '') {
            $language = $this->localization->findLanguage($explicit);

            if ($language?->is_active === true) {
                return $language;
            }
        }

        $user = current_actor();

        if ($user?->language !== null && $user->language->is_active) {
            return $user->language;
        }

        return $this->localization->negotiate($request->header('Accept-Language'));
    }
}
