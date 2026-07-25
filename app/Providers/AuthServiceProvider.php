<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Authorisation wiring.
 *
 * Policies are registered by convention (Filament and Laravel both resolve
 * App\Models\X -> App\Policies\XPolicy), so this provider handles only the
 * cross-cutting rules that do not belong to a single model.
 *
 * @see App\Core\Presentation\Policies\BasePolicy
 * @see docs/authorization.md
 */
final class AuthServiceProvider extends ServiceProvider
{
    /**
     * Model-to-policy map. Business modules register their own policies from
     * their module service providers rather than accumulating here.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        /*
        | Super-admin bypass, applied before any policy. Mirrors
        | BasePolicy::before() so the two cannot disagree — a Gate::define()
        | ability that has no policy still respects it.
        */
        Gate::before(static function (?User $user, string $ability): ?bool {
            // Gate runs before() for guests too, with a null user. A guest is
            // never a super-admin, so fall through (null) to the real checks.
            return $user?->isSuperAdmin() ? true : null;
        });

        /*
        | Log every authorisation denial. Denials are the earliest signal of
        | both a misconfigured role and a probing attacker, and they are
        | invisible otherwise — the user just sees a 403.
        */
        Gate::after(static function (?User $user, string $ability, ?bool $result): void {
            // A guest denial carries no actor to attribute; only log an
            // authenticated user's denial (the forensic signal we want).
            if ($result === false && $user instanceof User) {
                Log::channel('audit')->notice('Authorisation denied', [
                    'user_id' => $user->getKey(),
                    'user_type' => $user->type?->value,
                    'guard' => $user->guardName(),
                    'ability' => $ability,
                    'correlation_id' => correlation_id(),
                ]);
            }
        });
    }
}
