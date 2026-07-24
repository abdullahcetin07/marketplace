<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Identity\Application\Services\AuthService;
use App\Modules\Identity\Application\Services\SessionService;
use App\Modules\Identity\Domain\Models\UserSession;
use App\Modules\Identity\Presentation\Resources\SessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The user's security page: where am I signed in, and end it.
 *
 * AUTHORISATION IS ALWAYS VIA THE POLICY, never an inline ownership check.
 * UserSessionPolicy scopes reads as well as writes — a session list is IP
 * addresses and device fingerprints, and reading someone else's is a privacy
 * breach even without modifying it.
 */
final class SessionController extends BaseController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SessionService $sessions,
    ) {}

    /**
     * GET /api/v1/sessions
     */
    public function index(Request $request): JsonResponse
    {
        $user = current_actor();

        $this->authorize('viewAny', UserSession::class);

        return $this->ok(
            SessionResource::collection($this->auth->activeSessions($user)),
        );
    }

    /**
     * DELETE /api/v1/sessions/{session}
     *
     * Bound by UUID — @see HasUuid::getRouteKeyName(). The internal id is
     * never in a URL.
     */
    public function destroy(Request $request, UserSession $session): JsonResponse
    {
        $this->authorize('delete', $session);

        $this->sessions->revoke($session, current_actor(), 'manual');

        return $this->noContent();
    }

    /**
     * DELETE /api/v1/sessions
     *
     * "Sign out everywhere else." Keeps the calling session alive on purpose —
     * logging the user out of the tab they just used is hostile and they
     * simply sign back in.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $user = current_actor();

        $this->authorize('viewAny', UserSession::class);

        $revoked = $this->auth->signOutOtherDevices($user, $request);

        return $this->ok(['revoked' => $revoked]);
    }
}
