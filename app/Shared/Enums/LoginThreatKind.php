<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * How a run of failed logins against one address reads.
 *
 * Shared vocabulary, not Identity's alone: it is raised by Identity but travels
 * on SuspiciousLoginDetected to both Audit and Activity, which interpret it. An
 * enum that crosses modules on an event belongs here, alongside UserType and
 * NotificationType — keeping it inside a module would force the consumers to
 * import that module's Domain, which module isolation forbids.
 *
 *   BruteForce         — failures concentrated on few source IPs. Someone is
 *                        grinding one account's password.
 *   CredentialStuffing — failures spread across many source IPs. A botnet
 *                        replaying a breached credential list.
 *
 * @see App\Modules\Identity\Domain\Events\SuspiciousLoginDetected
 */
enum LoginThreatKind: string
{
    use HasEnumHelpers;

    case BruteForce = 'brute_force';
    case CredentialStuffing = 'credential_stuffing';
}
