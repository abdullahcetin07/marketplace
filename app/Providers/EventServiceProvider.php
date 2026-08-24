<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Domain\Events\BaseEvent;
use App\Core\Infrastructure\Mail\BlockedRecipientGuard;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Event wiring and the platform-wide audit subscriber.
 *
 * Modules register their own listeners in their own service providers. What
 * lives here is the cross-cutting behaviour: every domain event is audited,
 * and every authentication outcome is recorded.
 *
 * @see App\Core\Domain\Events\BaseEvent
 * @see docs/logging.md
 */
final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        //
    ];

    public function boot(): void
    {
        $this->auditDomainEvents();
        $this->auditAuthentication();
        $this->guardMailRecipients();
    }

    /**
     * Listener auto-discovery is off. On a modular codebase it scans every
     * module directory on every boot, and it makes the wiring implicit —
     * "what listens to this event?" should be answerable by reading a
     * provider, not by running a command.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    /**
     * Write every domain event to the audit channel.
     *
     * One wildcard listener rather than a listener per event: auditing is a
     * property of BaseEvent, so it should be implemented once at that level.
     * Individual events opt out via shouldAudit().
     */
    private function auditDomainEvents(): void
    {
        Event::listen('*', static function (string $eventName, array $payload): void {
            $event = $payload[0] ?? null;

            if (! $event instanceof BaseEvent || ! $event->shouldAudit()) {
                return;
            }

            Log::channel('audit')->info($event->name(), $event->toLogContext());
        });
    }

    /**
     * Authentication outcomes across all three guards.
     *
     * Failed logins and lockouts are the primary signal of credential
     * stuffing, and successful admin logins are the thing an auditor asks for
     * first after an incident.
     */
    private function auditAuthentication(): void
    {
        Event::listen(Login::class, static function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->recordLogin(request()->ip());
            }

            Log::channel('audit')->info('Login succeeded', [
                'guard' => $event->guard,
                'user_id' => $event->user->getAuthIdentifier(),
                'ip' => request()->ip(),
                'correlation_id' => correlation_id(),
            ]);
        });

        Event::listen(Failed::class, static function (Failed $event): void {
            Log::channel('audit')->warning('Login failed', [
                'guard' => $event->guard,
                // The attempted email is logged; the attempted password
                // never is, not even hashed.
                'email' => $event->credentials['email'] ?? null,
                'ip' => request()->ip(),
                'correlation_id' => correlation_id(),
            ]);
        });

        Event::listen(Lockout::class, static function (Lockout $event): void {
            Log::channel('errors')->warning('Authentication lockout triggered', [
                'ip' => $event->request->ip(),
                'email' => $event->request->input('email'),
            ]);
        });

        Event::listen(Logout::class, static function (Logout $event): void {
            Log::channel('audit')->info('Logout', [
                'guard' => $event->guard,
                'user_id' => $event->user?->getAuthIdentifier(),
            ]);
        });
    }

    /**
     * Keep undeliverable recipients away from the transports.
     *
     * Wired here rather than in a module because it is a property of sending
     * mail at all, and because the thing it protects — the failover chain — is
     * platform configuration. `MessageSending` is dispatched with `until()`, so
     * the guard can cancel a send by returning false.
     *
     * @see App\Core\Infrastructure\Mail\BlockedRecipientGuard
     */
    private function guardMailRecipients(): void
    {
        Event::listen(MessageSending::class, [BlockedRecipientGuard::class, 'handle']);
    }
}
