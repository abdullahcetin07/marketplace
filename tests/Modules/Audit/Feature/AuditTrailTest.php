<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Audit\Application\Services\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Enums\AuditSeverity;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Settings\Domain\Models\Setting;
use Illuminate\Support\Str;

/*
| The audit trail. Setting is the subject for the model-diff cases because it is
| a simple audited model; User is also auditable (ADR-027 / Phase 8) and is
| exercised at the end for the reason-and-exclusion rules that matter on a core
| aggregate.
|
| @see App\Modules\Audit\Domain\Concerns\Auditable
| @see docs/audit.md
*/

it('records a create with no before state', function (): void {
    $setting = Setting::factory()->create(['key' => 'company.name', 'value' => 'Acme']);

    $entry = AuditEntry::query()->forModel($setting)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event)->toBe(AuditEntry::EVENT_CREATED)
        ->and($entry->old_values)->toBeNull()
        ->and($entry->new_values)->toHaveKey('key');
});

it('classifies a model change and grades it Info (ADR-027)', function (): void {
    $setting = Setting::factory()->create();

    $entry = AuditEntry::query()->forModel($setting)->first();

    // The generic category rides alongside the fine-grained model verb, and a
    // routine model change is the lowest severity.
    expect($entry->event_type)->toBe(AuditEventType::ModelCreated)
        ->and($entry->severity)->toBe(AuditSeverity::Info)
        ->and($entry->event)->toBe(AuditEntry::EVENT_CREATED);
});

it('records only the attributes that actually changed', function (): void {
    $setting = Setting::factory()->create(['key' => 'company.name', 'value' => 'Old']);
    AuditEntry::query()->delete();

    $setting->update(['value' => 'New']);

    $entry = AuditEntry::query()->forModel($setting)->first();

    expect($entry->event)->toBe(AuditEntry::EVENT_UPDATED)
        ->and(array_keys($entry->new_values))->toBe(['value'])
        ->and($entry->old_values['value'])->toBe('Old')
        ->and($entry->new_values['value'])->toBe('New');
});

it('writes nothing for an idempotent save', function (): void {
    $setting = Setting::factory()->create(['value' => 'Same']);
    AuditEntry::query()->delete();

    $setting->update(['value' => 'Same']);

    // Nothing changed, so there is nothing to record. Otherwise every no-op
    // save pollutes the trail.
    expect(AuditEntry::query()->count())->toBe(0);
});

it('produces a readable before/after diff', function (): void {
    $setting = Setting::factory()->create(['value' => 'Before']);
    AuditEntry::query()->delete();
    $setting->update(['value' => 'After']);

    expect(AuditEntry::query()->first()->diff())
        ->toBe(['value' => ['old' => 'Before', 'new' => 'After']]);
});

/*
| IMMUTABILITY
|
| An editable audit trail is not an audit trail. Enforced by the model, not by
| convention or by a policy that someone could bypass in a console command.
*/
it('refuses to be updated', function (): void {
    $entry = AuditEntry::factory()->create();

    $result = $entry->update(['event' => 'tampered']);

    expect($result)->toBeFalse()
        ->and($entry->fresh()->event)->not->toBe('tampered');
});

it('refuses to be deleted', function (): void {
    $entry = AuditEntry::factory()->create();

    expect($entry->delete())->toBeFalse()
        ->and(AuditEntry::query()->whereKey($entry->getKey())->exists())->toBeTrue();
});

/*
| CAUSER ATTRIBUTION
*/
it('attributes the change to the authenticated actor', function (): void {
    $admin = $this->actingAsAdmin();

    $setting = Setting::factory()->create();

    $entry = AuditEntry::query()->forModel($setting)->first();

    expect($entry->causer_id)->toBe($admin->getKey())
        ->and($entry->causer_type)->toBe(Admin::class);
});

it('leaves the causer null for a system write', function (): void {
    // No authenticated actor — a seeder, a worker, a console command.
    // Attributing these to a person would make the trail lie.
    $setting = Setting::factory()->create();

    expect(AuditEntry::query()->forModel($setting)->first()->causer_id)->toBeNull();
});

/*
| CREDENTIAL EXCLUSION
|
| An audit trail containing password hashes is a credential store with a long
| retention policy and a permissive read scope.
*/
it('never records excluded credential attributes', function (): void {
    $setting = Setting::factory()->create();
    $entry = AuditEntry::query()->forModel($setting)->first();

    $payload = json_encode([$entry->old_values, $entry->new_values]);

    foreach (['password', 'remember_token', 'two_factor_secret'] as $forbidden) {
        expect($payload)->not->toContain($forbidden);
    }
});

it('can be suppressed for bulk operations', function (): void {
    Setting::withoutAuditing(function (): void {
        Setting::factory()->count(3)->create();
    });

    expect(AuditEntry::query()->count())->toBe(0);
});

it('resumes auditing after a suppressed block', function (): void {
    Setting::withoutAuditing(fn () => Setting::factory()->create());

    Setting::factory()->create();

    expect(AuditEntry::query()->count())->toBe(1);
});

/*
| FORENSIC EVENTS WITH NO MODEL DIFF (ADR-027)
|
| A detected attack has an actor, an IP and a severity, but nothing changed on a
| record. AuditLogger writes it as a standalone row.
*/
it('records a standalone security event through AuditLogger', function (): void {
    $admin = Admin::factory()->create();

    app(AuditLogger::class)->record(
        type: AuditEventType::SecurityBruteForce,
        severity: AuditSeverity::High,
        auditable: $admin,
        metadata: ['email' => 'target@example.test', 'failure_count' => 12],
    );

    $entry = AuditEntry::query()->security()->first();

    expect($entry->event_type)->toBe(AuditEventType::SecurityBruteForce)
        ->and($entry->severity)->toBe(AuditSeverity::High)
        ->and($entry->metadata)->toHaveKey('failure_count')
        // No model verb, no diff — that is what makes it "standalone".
        ->and($entry->event)->toBeNull()
        ->and($entry->old_values)->toBeNull()
        ->and($entry->new_values)->toBeNull();
});

it('records a security event with no auditable when the target has no account', function (): void {
    app(AuditLogger::class)->record(
        type: AuditEventType::SecurityCredentialStuffing,
        severity: AuditSeverity::Critical,
        auditable: null,
        metadata: ['email' => 'ghost@example.test'],
    );

    $entry = AuditEntry::query()->security()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->auditable_type)->toBeNull()
        ->and($entry->auditable_id)->toBeNull();
});

it('filters the security trail and by severity floor', function (): void {
    Setting::factory()->create();                                   // model change, Info
    app(AuditLogger::class)->record(AuditEventType::SecurityLogin, AuditSeverity::Notice);
    app(AuditLogger::class)->record(AuditEventType::SecurityBruteForce, AuditSeverity::High);

    // scopeSecurity ignores the model change; scopeAtLeastSeverity is a floor.
    expect(AuditEntry::query()->security()->count())->toBe(2)
        ->and(AuditEntry::query()->atLeastSeverity(AuditSeverity::High)->count())->toBe(1)
        ->and(AuditEntry::query()->atLeastSeverity(AuditSeverity::Notice)->count())->toBe(2);
});

/*
| USER AS AN AUDITABLE AGGREGATE (Phase 8)
|
| Every account change leaves an immutable before/after record, the same rule
| for admin and self-service. Sensitive columns never appear; an admin-supplied
| reason does.
*/
it('audits a user change and never records the credential columns', function (): void {
    $customer = App\Models\Customer::factory()->create();
    AuditEntry::query()->delete();

    $customer->update(['phone' => '+900000000', 'password' => bcrypt('a-new-secret')]);

    $entry = AuditEntry::query()->forModel($customer)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event_type)->toBe(AuditEventType::ModelUpdated)
        ->and(array_keys($entry->new_values))->toContain('phone');

    // The credential columns are globally excluded — even though password
    // changed on this very save, it must not be in the trail.
    $payload = json_encode([$entry->old_values, $entry->new_values]);
    foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $forbidden) {
        expect($payload)->not->toContain($forbidden);
    }
});

it('records the reason an actor gave for a change', function (): void {
    $customer = App\Models\Customer::factory()->create();
    AuditEntry::query()->delete();

    App\Core\Domain\Context\AuditContext::withReasonFor('GDPR erasure request #123', function () use ($customer): void {
        $customer->update(['first_name' => 'Redacted']);
    });

    $entry = AuditEntry::query()->forModel($customer)->first();

    expect($entry->metadata)->toBe(['reason' => 'GDPR erasure request #123']);
});

it('leaves metadata null for a change with no reason', function (): void {
    $customer = App\Models\Customer::factory()->create();
    AuditEntry::query()->delete();

    $customer->update(['first_name' => 'NoReason']);

    expect(AuditEntry::query()->forModel($customer)->first()->metadata)->toBeNull();
});

it('ties entries to the request correlation id', function (): void {
    $correlationId = (string) Str::uuid();
    app()->instance('correlation_id', $correlationId);

    $setting = Setting::factory()->create();

    expect(AuditEntry::query()->forModel($setting)->first()->correlation_id)
        ->toBe($correlationId);

    // Everything from one request is retrievable together — the whole point
    // of the correlation id during an incident.
    expect(AuditEntry::query()->forCorrelation($correlationId)->count())->toBe(1);
});
