<?php

declare(strict_types=1);

namespace App\Modules\Settings\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Sections of the settings screen.
 *
 * An enum, not a table: each group corresponds to a tab an engineer builds and
 * to a permission an operator is granted. Groups are not data an administrator
 * invents at runtime.
 */
enum SettingGroup: string
{
    use HasEnumHelpers;

    case General = 'general';
    case Company = 'company';
    case Email = 'email';
    case Sms = 'sms';
    case Media = 'media';
    case Seo = 'seo';
    case Localization = 'localization';
    case Security = 'security';
    case Performance = 'performance';
    case System = 'system';

    /**
     * Groups only a super-admin may change.
     *
     * System and Security settings can break authentication or take the
     * platform offline; they are deliberately not reachable by an Editor or a
     * Support operator who otherwise holds `setting.update`.
     *
     * @return array<int, self>
     */
    public static function restricted(): array
    {
        return [self::System, self::Security, self::Performance];
    }

    public function isRestricted(): bool
    {
        return in_array($this, self::restricted(), true);
    }

    /**
     * Whether values in this group may be exposed to the public API.
     *
     * Deny-by-default: only groups explicitly listed here can be read without
     * authentication, and a setting must ALSO be flagged `is_public` on its
     * own row. Two gates, because a single forgotten flag on an SMTP password
     * would publish a credential.
     */
    public function isPubliclyReadable(): bool
    {
        return in_array($this, [self::General, self::Company, self::Seo, self::Localization], true);
    }

    public function icon(): string
    {
        return match ($this) {
            self::General => 'heroicon-o-cog-6-tooth',
            self::Company => 'heroicon-o-building-office',
            self::Email => 'heroicon-o-envelope',
            self::Sms => 'heroicon-o-chat-bubble-left-right',
            self::Media => 'heroicon-o-photo',
            self::Seo => 'heroicon-o-magnifying-glass',
            self::Localization => 'heroicon-o-language',
            self::Security => 'heroicon-o-shield-check',
            self::Performance => 'heroicon-o-bolt',
            self::System => 'heroicon-o-server-stack',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::General => 1,
            self::Company => 2,
            self::Localization => 3,
            self::Email => 4,
            self::Sms => 5,
            self::Media => 6,
            self::Seo => 7,
            self::Security => 8,
            self::Performance => 9,
            self::System => 10,
        };
    }
}
