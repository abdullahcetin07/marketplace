<?php

declare(strict_types=1);

namespace Database\Modules\Settings\Seeders;

use App\Modules\Settings\Application\Services\SettingsService;
use App\Modules\Settings\Domain\Enums\SettingGroup;
use App\Modules\Settings\Domain\Enums\SettingType;
use Illuminate\Database\Seeder;

/**
 * Registers the platform's settings with their defaults.
 *
 * Uses SettingsService::register(), which only ever fills metadata — it never
 * overwrites a value an operator has set. That is what makes this safe to run
 * on every deploy: a redeploy must not silently reset the company address back
 * to a placeholder.
 *
 * `locked` marks settings that code reads by key. They are displayable but not
 * editable or deletable, because renaming one turns a working feature into a
 * runtime null dereference.
 */
final class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsService::class);

        // -------------------------------------------------------------- General
        $settings->register('general.site_name', SettingGroup::General, SettingType::String, 'MarketplaceOS', 'Site name', isPublic: true);
        $settings->register('general.tagline', SettingGroup::General, SettingType::String, '', 'Tagline', isPublic: true);
        $settings->register('general.maintenance_message', SettingGroup::General, SettingType::Text, '', 'Maintenance message', isPublic: true);
        $settings->register('general.support_email', SettingGroup::General, SettingType::String, 'destek@marketplaceos.test', 'Support email', isPublic: true);

        // -------------------------------------------------------------- Company
        $settings->register('company.legal_name', SettingGroup::Company, SettingType::String, '', 'Legal entity name', isPublic: true);
        $settings->register('company.tax_number', SettingGroup::Company, SettingType::String, '', 'Tax number');
        $settings->register('company.tax_office', SettingGroup::Company, SettingType::String, '', 'Tax office');
        $settings->register('company.address', SettingGroup::Company, SettingType::Text, '', 'Registered address', isPublic: true);
        $settings->register('company.phone', SettingGroup::Company, SettingType::String, '', 'Phone', isPublic: true);
        // MERSIS is the Turkish central trade registry number — legally
        // required on commercial sites operating in Türkiye.
        $settings->register('company.mersis_no', SettingGroup::Company, SettingType::String, '', 'MERSIS number', isPublic: true);

        // ---------------------------------------------------------------- Email
        $settings->register('email.from_name', SettingGroup::Email, SettingType::String, 'MarketplaceOS', 'From name');
        $settings->register('email.from_address', SettingGroup::Email, SettingType::String, 'no-reply@marketplaceos.test', 'From address');
        $settings->register('email.reply_to', SettingGroup::Email, SettingType::String, '', 'Reply-to address');
        // Encrypted: a business-owned third-party credential, as opposed to a
        // deployment secret (which belongs in .env).
        $settings->register('email.smtp_password', SettingGroup::Email, SettingType::String, null, 'SMTP password', isEncrypted: true);
        $settings->register('email.footer_text', SettingGroup::Email, SettingType::Text, '', 'Email footer');

        // ------------------------------------------------------------------ SMS
        $settings->register('sms.enabled', SettingGroup::Sms, SettingType::Boolean, false, 'SMS enabled');
        $settings->register('sms.sender_id', SettingGroup::Sms, SettingType::String, '', 'Sender ID');
        $settings->register('sms.api_key', SettingGroup::Sms, SettingType::String, null, 'Provider API key', isEncrypted: true);

        // ---------------------------------------------------------------- Media
        $settings->register('media.max_upload_mb', SettingGroup::Media, SettingType::Integer, 10, 'Max upload size (MB)');
        $settings->register('media.image_quality', SettingGroup::Media, SettingType::Integer, 82, 'Image quality (1-100)');
        $settings->register('media.generate_webp', SettingGroup::Media, SettingType::Boolean, true, 'Generate WebP variants');
        $settings->register('media.responsive_widths', SettingGroup::Media, SettingType::Json, [320, 640, 960, 1280, 1920], 'Responsive widths');

        // ------------------------------------------------------------------ SEO
        $settings->register('seo.default_title', SettingGroup::Seo, SettingType::String, 'MarketplaceOS', 'Default meta title', isPublic: true);
        $settings->register('seo.default_description', SettingGroup::Seo, SettingType::Text, '', 'Default meta description', isPublic: true);
        $settings->register('seo.robots_indexable', SettingGroup::Seo, SettingType::Boolean, true, 'Allow indexing', isPublic: true);
        $settings->register('seo.google_analytics_id', SettingGroup::Seo, SettingType::String, '', 'Google Analytics ID', isPublic: true);

        // --------------------------------------------------------- Localization
        $settings->register('localization.date_format', SettingGroup::Localization, SettingType::String, 'd.m.Y', 'Date format', isPublic: true);
        $settings->register('localization.time_format', SettingGroup::Localization, SettingType::String, 'H:i', 'Time format', isPublic: true);
        $settings->register('localization.first_day_of_week', SettingGroup::Localization, SettingType::Integer, 1, 'First day of week', isPublic: true);
        $settings->register('localization.auto_detect', SettingGroup::Localization, SettingType::Boolean, true, 'Detect language from browser', isPublic: true);

        // ------------------------------------------------------------- Security
        $settings->register('security.session_lifetime_minutes', SettingGroup::Security, SettingType::Integer, 120, 'Session lifetime (minutes)');
        $settings->register('security.max_login_attempts', SettingGroup::Security, SettingType::Integer, 5, 'Max login attempts per minute');
        $settings->register('security.password_expiry_days', SettingGroup::Security, SettingType::Integer, 0, 'Force password change after N days (0 = never)');
        $settings->register('security.two_factor_required_for_admins', SettingGroup::Security, SettingType::Boolean, false, 'Require 2FA for administrators');
        $settings->register('security.notify_on_new_device', SettingGroup::Security, SettingType::Boolean, true, 'Email on sign-in from a new device');

        // ---------------------------------------------------------- Performance
        $settings->register('performance.cache_ttl_seconds', SettingGroup::Performance, SettingType::Integer, 3600, 'Default cache TTL');
        $settings->register('performance.api_rate_limit', SettingGroup::Performance, SettingType::Integer, 60, 'API requests per minute');

        // ------------------------------------------------------------- Shipping
        /*
        | THE THREE FULFILMENT WINDOWS (ADR-064). Operator-tunable because the
        | right answer comes from what carriers actually do and what support
        | tickets say, not from a release — the platform's own "who owns the
        | value" test. `config('shipping.windows.*')` holds the same numbers as
        | the fallback for when the settings table is unreachable, because a
        | module that stopped inferring deliveries over a missing row would stop
        | paying sellers.
        |
        | `transit_days` IS DELIBERATELY GENEROUS. Its failure mode is asymmetric:
        | too long and a seller waits a few extra days; too short and the platform
        | tells a buyer their parcel arrived while they are still waiting for it,
        | and starts their return clock running.
        */
        /*
        | HOW LONG A PLACED ORDER MAY GO UNPAID before its stock hold goes back
        | (ADR-072). The number that fixes a real, live bug: without the sweep
        | this feeds, an abandoned payment held a seller's stock forever and
        | took their offer off the buy box while it still declared stock.
        |
        | FIVE MINUTES IS AGGRESSIVE ON PURPOSE — a hold that outlives the
        | shopper's attention costs the seller every sale it blocks — and the
        | cost is stated where the config default is: it is shorter than PayTR's
        | own iframe session, so a slow 3-D Secure can expire mid-payment. The
        | late-payment path re-reserves or refunds precisely because of that,
        | and this row is `settings()` so an operator can lengthen it from the
        | panel if support sees churn.
        */
        $settings->register('order.payment_window_minutes', SettingGroup::Order, SettingType::Integer, 5, 'Ödeme penceresi (dk): ödenmeyen sipariş bu süre sonunda düşer ve stok serbest kalır.');

        $settings->register('shipping.transit_days', SettingGroup::Shipping, SettingType::Integer, 3, 'Transit days before delivery is inferred');
        $settings->register('shipping.payout_hold_days', SettingGroup::Shipping, SettingType::Integer, 14, 'Days after delivery before a payout is eligible');
        // 14 days is the Turkish distance-selling right of withdrawal (cayma
        // hakkı). Shortening it below that is not a configuration choice the law
        // allows — worth knowing before anyone edits this row.
        $settings->register('shipping.return_days', SettingGroup::Shipping, SettingType::Integer, 14, 'Days after delivery a buyer may still return');

        // --------------------------------------------------------------- System
        // Locked: read by code at boot. Renaming or deleting one is a runtime
        // failure, not a configuration change.
        $settings->register('system.installed_at', SettingGroup::System, SettingType::String, now()->toIso8601String(), 'Installed at', isLocked: true);
        $settings->register('system.schema_version', SettingGroup::System, SettingType::String, '1.0.0', 'Schema version', isLocked: true);
        $settings->register('system.maintenance_mode', SettingGroup::System, SettingType::Boolean, false, 'Maintenance mode');
        $settings->register('system.registration_enabled', SettingGroup::System, SettingType::Boolean, true, 'Allow seller self-registration');
    }
}
