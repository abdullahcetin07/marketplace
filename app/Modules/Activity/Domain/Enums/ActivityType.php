<?php

declare(strict_types=1);

namespace App\Modules\Activity\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The kinds of thing a user does that are worth remembering.
 *
 * An enum, not a table: each case is referenced by code that records it, so a
 * new case is a code change by definition. Contrast with Country/Currency/
 * Language, which are operational data.
 *
 * Sprint 1 scope is identity and account activity. Business activity
 * (order placed, offer published) arrives with those modules and extends this
 * enum.
 */
enum ActivityType: string
{
    use HasEnumHelpers;

    case Login = 'login';
    case Logout = 'logout';
    case LoginFailed = 'login_failed';
    case SuspiciousLogin = 'suspicious_login';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case EmailVerified = 'email_verified';
    case ProfileUpdated = 'profile_updated';
    case PermissionChanged = 'permission_changed';
    case RoleChanged = 'role_changed';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case SessionRevoked = 'session_revoked';
    case DeviceTrusted = 'device_trusted';
    case SettingsUpdated = 'settings_updated';

    /**
     * Types the user themselves should see on their own account page. Failed
     * logins are included deliberately — a user noticing attempts they did not
     * make is the cheapest intrusion detection available.
     *
     * @return array<int, self>
     */
    public static function userVisible(): array
    {
        return [
            self::Login,
            self::Logout,
            self::LoginFailed,
            self::SuspiciousLogin,
            self::PasswordChanged,
            self::PasswordReset,
            self::EmailVerified,
            self::ProfileUpdated,
            self::TwoFactorEnabled,
            self::TwoFactorDisabled,
            self::SessionRevoked,
            self::DeviceTrusted,
        ];
    }

    /**
     * Types that warrant notifying the account owner when they occur.
     *
     * All of these are things an attacker does early: change the password,
     * disable 2FA, alter permissions. A notification the user did not expect
     * is the alarm.
     *
     * @return array<int, self>
     */
    public static function securitySensitive(): array
    {
        return [
            self::SuspiciousLogin,
            self::PasswordChanged,
            self::PasswordReset,
            self::PermissionChanged,
            self::RoleChanged,
            self::TwoFactorEnabled,
            self::TwoFactorDisabled,
        ];
    }

    public function isUserVisible(): bool
    {
        return in_array($this, self::userVisible(), true);
    }

    public function isSecuritySensitive(): bool
    {
        return in_array($this, self::securitySensitive(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Login, self::EmailVerified, self::TwoFactorEnabled => 'success',
            self::LoginFailed, self::SuspiciousLogin, self::TwoFactorDisabled => 'danger',
            self::PasswordChanged, self::PasswordReset,
            self::PermissionChanged, self::RoleChanged, self::SessionRevoked => 'warning',
            default => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Login => 'heroicon-o-arrow-right-on-rectangle',
            self::Logout => 'heroicon-o-arrow-left-on-rectangle',
            self::LoginFailed => 'heroicon-o-exclamation-triangle',
            self::SuspiciousLogin => 'heroicon-o-shield-exclamation',
            self::PasswordChanged, self::PasswordReset => 'heroicon-o-key',
            self::EmailVerified => 'heroicon-o-check-badge',
            self::ProfileUpdated => 'heroicon-o-user',
            self::PermissionChanged, self::RoleChanged => 'heroicon-o-shield-check',
            self::TwoFactorEnabled, self::TwoFactorDisabled => 'heroicon-o-device-phone-mobile',
            self::SessionRevoked => 'heroicon-o-x-circle',
            self::DeviceTrusted => 'heroicon-o-computer-desktop',
            self::SettingsUpdated => 'heroicon-o-cog-6-tooth',
        };
    }
}
