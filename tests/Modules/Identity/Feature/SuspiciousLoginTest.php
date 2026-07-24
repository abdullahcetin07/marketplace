<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Enums\AuditSeverity;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Identity\Domain\Events\SuspiciousLoginDetected;
use App\Modules\Identity\Domain\Models\LoginAttempt;
use App\Modules\Identity\Infrastructure\Notifications\SecurityAlertNotification;
use App\Modules\Identity\Infrastructure\Notifications\SuspiciousLoginNotification;
use App\Shared\Enums\LoginThreatKind;
use Database\Factories\UserFactory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Suspicious-login detection (Q6)
|--------------------------------------------------------------------------
|
| A run of failed logins against one address raises SuspiciousLoginDetected,
| which fans out to a forensic audit entry, an activity-timeline line, and
| notifications to the owner and the administrators.
|
| The rate limiter is removed here on purpose: it caps requests at 5/min, but
| this behaviour lives ABOVE the limiter — it is about a sustained campaign, not
| a single burst — so the test seeds prior attempts and drives the crossing
| directly.
|
| @see App\Modules\Identity\Application\Services\AuthService::classifyThreat()
| @see docs/modules/Identity.md §Q6
*/

beforeEach(function (): void {
    // Roles + permissions too: admin alert routing is gated on
    // `security.receive_alerts`, so the permission must exist and be assignable.
    $this->seedAll();
    $this->withoutMiddleware(ThrottleRequests::class);
});

function failedLogin(string $email = 'buyer@example.test'): void
{
    test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'definitely-wrong',
        'type' => 'customer',
    ]);
}

/**
 * Seed prior failed attempts so one more crossing the threshold is cheap to
 * drive, without hammering the endpoint dozens of times.
 *
 * @param  array<int, string>  $ips  one row per IP; repeats concentrate on one
 */
function seedFailures(string $email, array $ips): void
{
    foreach ($ips as $ip) {
        LoginAttempt::factory()->create([
            'email' => $email,
            'guard' => 'customer',
            'successful' => false,
            'ip_address' => $ip,
        ]);
    }
}

it('raises the event once the failure threshold is crossed', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);
    Event::fake([SuspiciousLoginDetected::class]);

    // Nine seeded + one live failure = ten, the brute-force default.
    seedFailures('buyer@example.test', array_fill(0, 9, '203.0.113.10'));
    failedLogin();

    Event::assertDispatched(
        SuspiciousLoginDetected::class,
        fn (SuspiciousLoginDetected $e): bool => $e->kind === LoginThreatKind::BruteForce
            && $e->email === 'buyer@example.test',
    );
});

it('stays silent below the threshold', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);
    Event::fake([SuspiciousLoginDetected::class]);

    seedFailures('buyer@example.test', array_fill(0, 3, '203.0.113.10'));
    failedLogin();

    Event::assertNotDispatched(SuspiciousLoginDetected::class);
});

it('alerts at most once per cooldown despite continued attempts', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);
    Event::fake([SuspiciousLoginDetected::class]);

    seedFailures('buyer@example.test', array_fill(0, 9, '203.0.113.10'));
    failedLogin(); // crosses — one alert
    failedLogin(); // still under attack — must not alert again
    failedLogin();

    Event::assertDispatchedTimes(SuspiciousLoginDetected::class, 1);
});

it('notifies the account owner and the alert-permitted admins', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
    // Holds security.receive_alerts (via the Support role) — a recipient.
    $responder = Admin::factory()->support()->create();
    // A plain admin does NOT hold the permission — must NOT be paged.
    $plainAdmin = Admin::factory()->admin()->create();
    Notification::fake();

    seedFailures('buyer@example.test', array_fill(0, 9, '203.0.113.10'));
    failedLogin();

    Notification::assertSentTo($customer, SuspiciousLoginNotification::class);
    Notification::assertSentTo($responder, SecurityAlertNotification::class);
    Notification::assertNotSentTo($plainAdmin, SecurityAlertNotification::class);
});

it('does not notify an owner when the attacked address has no account', function (): void {
    $admin = Admin::factory()->support()->create();
    Notification::fake();

    // No customer for this address — a stuffing run against a dead address.
    seedFailures('ghost@example.test', array_fill(0, 9, '203.0.113.10'));
    test()->postJson('/api/v1/auth/login', [
        'email' => 'ghost@example.test',
        'password' => 'wrong',
        'type' => 'customer',
    ]);

    // Admins still hear about it; there is simply no owner to warn. No
    // SuspiciousLoginNotification is sent at all, because nobody owns the
    // address.
    Notification::assertSentTo($admin, SecurityAlertNotification::class);
    Notification::assertNotSentTo($admin, SuspiciousLoginNotification::class);
});

it('writes a high-severity forensic audit entry for brute force', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);

    seedFailures('buyer@example.test', array_fill(0, 9, '203.0.113.10'));
    failedLogin();

    $entry = AuditEntry::query()->security()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event_type)->toBe(AuditEventType::SecurityBruteForce)
        ->and($entry->severity)->toBe(AuditSeverity::High)
        ->and($entry->metadata)->toHaveKey('email')
        // A security event is a standalone row — no model verb, no diff.
        ->and($entry->event)->toBeNull()
        ->and($entry->old_values)->toBeNull();
});

it('grades credential stuffing across many IPs as critical', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);

    // Five failures from three distinct IPs — the stuffing signature.
    seedFailures('buyer@example.test', ['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.1']);
    failedLogin(); // adds the sixth failure, a fourth distinct IP

    $entry = AuditEntry::query()->security()->first();

    expect($entry->event_type)->toBe(AuditEventType::SecurityCredentialStuffing)
        ->and($entry->severity)->toBe(AuditSeverity::Critical);
});

it('never lets a detection fault turn a wrong password into a 500', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);
    seedFailures('buyer@example.test', array_fill(0, 9, '203.0.113.10'));

    // The tenth failure both crosses the threshold and must still return the
    // ordinary enumeration-safe 401.
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'buyer@example.test',
        'password' => UserFactory::PASSWORD.'-nope',
        'type' => 'customer',
    ]);

    $response->assertStatus(401);
});
