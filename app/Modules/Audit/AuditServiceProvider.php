<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Application\Listeners\RecordSecurityAudit;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Audit\Presentation\Policies\AuditEntryPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Audit module wiring.
 *
 * @see docs/audit.md
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
        | Read-only by design: there is no create, update or delete verb for an
        | audit entry, because an editable audit trail is not an audit trail.
        | The model blocks writes; these are the permissions that let someone
        | READ the trail, which is itself sensitive — entries contain the
        | before/after values of every field.
        */
        PermissionRegistry::ability('audit.view_any', [UserType::Admin]);
        PermissionRegistry::ability('audit.view', [UserType::Admin]);
        PermissionRegistry::ability('audit.export', [UserType::Admin]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Audit/migrations'));

        Gate::policy(AuditEntry::class, AuditEntryPolicy::class);

        /*
        | Audit subscribes to security events from across the platform (ADR-027).
        | The producers announce and stay ignorant of the trail; Audit records.
        | Same direction as Activity's subscription — the consumer knows the
        | event contract, never the reverse.
        */
        Event::subscribe(RecordSecurityAudit::class);
    }
}
