<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Defaults
    |--------------------------------------------------------------------------
    |
    | Domain-level settings that are not Laravel's business. Keeping them here
    | rather than scattered through config/app.php means a new market can be
    | opened by changing one file.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Frontend Routes (ADR-025)
    |--------------------------------------------------------------------------
    |
    | The backend composes links into the storefront from CONFIGURATION ONLY.
    | It never hardcodes a frontend URL, and it never returns a credential in an
    | API response so the frontend can build one itself — a reset token in a
    | response body is an account-takeover vector.
    |
    | Placeholders are substituted by App\Core\Infrastructure\Frontend\FrontendUrl.
    | A second frontend — mobile app, admin SPA — needs one environment value,
    | not a backend change.
    |
    */

    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:3000'),
        'password_reset_path' => env('FRONTEND_PASSWORD_RESET_PATH', '/reset-password/{token}'),
        'email_verify_path' => env('FRONTEND_EMAIL_VERIFY_PATH', '/verify-email/{id}/{hash}'),
        'organization_invitation_path' => env('FRONTEND_ORG_INVITATION_PATH', '/organizations/invitations/{token}'),

        // Where a post-delivery review invitation sends the buyer (ADR-087). The
        // orders page, not a deep link into one review form: the storefront owns
        // that flow and its URL, and a backend that guesses at a frontend route is
        // how a mail campaign starts 404ing after a redesign.
        'orders_path' => env('FRONTEND_ORDERS_PATH', '/hesap/siparislerim'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Localisation Bootstrap
    |--------------------------------------------------------------------------
    |
    | Country, Currency and Language are LOOKUP TABLES, not enums — an operator
    | must be able to add or disable one without a deploy.
    | @see docs/001_Architecture.md §"Enums vs lookup tables"
    |
    | These ISO codes are the bootstrap seed: which row the installer marks
    | default, and what the application falls back to before the tables are
    | populated. The live default is `is_default` on the row itself.
    |
    */

    'localization' => [
        'default_currency' => env('MARKETPLACE_CURRENCY', 'TRY'),
        'default_country' => env('MARKETPLACE_COUNTRY', 'TR'),
        'default_language' => env('APP_LOCALE', 'tr'),

        // The locale translation keys are AUTHORED in. Missing keys fall back
        // here, so it is a developer concern rather than an operator one and
        // is deliberately not a flag on the languages table.
        'fallback_language' => env('APP_FALLBACK_LOCALE', 'en'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Names
    |--------------------------------------------------------------------------
    |
    | Roles are referenced by NAME everywhere — never by id. Ids are assigned by
    | the database, differ per environment, and mean nothing; a seeded id that
    | leaks into application code is a bug waiting for the first production
    | reseed.
    |
    | These constants exist so a rename is a one-line change rather than a
    | grep-and-pray across the codebase.
    |
    | Guard assignment for each role lives in RolePermissionSeeder.
    |
    | @see App\Shared\Support\PermissionRegistry
    | @see docs/authorization.md
    |
    */

    'roles' => [
        /*
        | admin guard
        |
        | `super_admin` and `admin` are DISTINCT. Super Admin bypasses every
        | policy via BasePolicy::before(); Admin is a normal role that holds a
        | broad but enumerated permission set. Collapsing them — as Sprint 0
        | did — means every operator who needs broad access gets an
        | unauditable bypass.
        */
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'editor' => 'Editor',
        'category_manager' => 'Category Manager',
        'support' => 'Support',
        'finance' => 'Finance',

        // seller guard
        'seller' => 'Seller',
        'seller_employee' => 'Seller Employee',

        // customer guard
        'customer' => 'Customer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | Two disks: public assets served from the CDN-fronted bucket, and private
    | documents (tax certificates, ID scans) that are only ever reachable
    | through a signed, short-lived URL.
    |
    | @see App\Shared\Traits\HasMedia
    |
    */

    'media' => [
        'public_disk' => env('MEDIA_PUBLIC_DISK', 's3'),
        'private_disk' => env('MEDIA_PRIVATE_DISK', 's3-private'),
        'max_upload_bytes' => (int) env('MEDIA_MAX_UPLOAD_BYTES', 10 * 1024 * 1024),
        'signed_url_ttl' => (int) env('MEDIA_SIGNED_URL_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | `max_per_page` is a hard ceiling enforced by BaseController::perPage() and
    | BaseRepository::paginate(). Without it, `?per_page=100000` is a free
    | denial-of-service against the database.
    |
    */

    'pagination' => [
        'per_page' => 25,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | `two_factor.enforced_for` lists the actor types that MUST complete 2FA
    | enrolment. The columns and model support are in place from Sprint 0; the
    | enrolment flow ships with the auth module, at which point flipping this
    | to ['admin'] is the entire rollout.
    |
    */

    'security' => [
        'two_factor' => [
            'enabled' => (bool) env('TWO_FACTOR_ENABLED', false),
            'enforced_for' => array_filter(explode(',', (string) env('TWO_FACTOR_ENFORCED_FOR', ''))),
            'window' => 1, // ±30s clock drift tolerance

            // TOTP secret length, used by Google2FaTotpProvider.
            'secret_length' => (int) env('TWO_FACTOR_SECRET_LENGTH', 32),

            /*
            | Recovery codes are configuration, not hardcoded (ADR-026 / 002
            | §16). `hash` names any registered Laravel hasher — bcrypt,
            | argon, argon2id. Changing it affects only NEW codes; Hash::check
            | detects the algorithm of existing ones from their prefix.
            */
            'recovery_codes' => [
                'count' => (int) env('TWO_FACTOR_RECOVERY_CODE_COUNT', 8),
                'length' => (int) env('TWO_FACTOR_RECOVERY_CODE_LENGTH', 5),
                'hash' => env('TWO_FACTOR_RECOVERY_CODE_HASH', 'bcrypt'),
            ],

            /*
            | Email-OTP fallback (Q5). A short-lived single-use code, ranked
            | below TOTP and recovery codes. Five minutes is long enough for a
            | mail round-trip, short enough to bound a brute-force window.
            */
            'email_otp_ttl_seconds' => (int) env('TWO_FACTOR_EMAIL_OTP_TTL', 300),
        ],

        /*
        | Email verification link lifetime. The signed callback expires after
        | this; a clicked-too-late link produces one indistinguishable failure
        | and the user requests a fresh one.
        */
        'email_verification' => [
            'expire_minutes' => (int) env('EMAIL_VERIFICATION_EXPIRE_MINUTES', 60),

            /*
            | How long a "trust this device" grant skips the 2FA challenge.
            | Time-limited on purpose: indefinite trust is a permanent 2FA
            | bypass on hardware the user may no longer own.
            | @see App\Modules\Identity\Domain\Models\UserDevice::isTrusted()
            */
            'trust_days' => (int) env('TWO_FACTOR_TRUST_DAYS', 30),
        ],

        /*
        | Suspicious-login detection (Q6). Distinct from the rate limiter: that
        | throttles and forgets, this classifies a run of failures against one
        | address and drives the owner + admin alert and a high-severity audit
        | entry. Thresholds are configuration so an operator can tune false
        | positives without a release.
        |
        | @see App\Modules\Identity\Application\Services\AuthService::classifyThreat()
        */
        'suspicious_login' => [
            'window_minutes' => (int) env('SUSPICIOUS_LOGIN_WINDOW_MINUTES', 60),
            // Concentrated failures — someone grinding one password.
            'brute_force_failures' => (int) env('SUSPICIOUS_LOGIN_BRUTE_FORCE_FAILURES', 10),
            // Failures spread across many IPs — a botnet replaying a list.
            'stuffing_failures' => (int) env('SUSPICIOUS_LOGIN_STUFFING_FAILURES', 5),
            'stuffing_distinct_ips' => (int) env('SUSPICIOUS_LOGIN_STUFFING_DISTINCT_IPS', 3),
            // One alert per address per cooldown, so a sustained attack does not
            // bury the owner and admins under a message per attempt.
            'alert_cooldown_minutes' => (int) env('SUSPICIOUS_LOGIN_ALERT_COOLDOWN_MINUTES', 60),
        ],

        /*
        | Session and device management.
        | @see App\Modules\Identity\Domain\Models\UserSession
        */
        'sessions' => [
            // Sessions idle longer than this are pruned by the scheduler.
            'prune_after_days' => (int) env('SESSION_PRUNE_DAYS', 30),
            // Concurrent sessions per user per guard; 0 disables the limit.
            'max_concurrent' => (int) env('SESSION_MAX_CONCURRENT', 0),
        ],

        /*
        | Retention. Audit and activity answer different questions and are kept
        | for different periods — audit is dispute evidence, activity is
        | operational history. @see docs/audit.md
        */
        'retention' => [
            'audit_days' => (int) env('AUDIT_RETENTION_DAYS', 730),
            'activity_days' => (int) env('ACTIVITY_RETENTION_DAYS', 365),
            'login_attempt_days' => (int) env('LOGIN_ATTEMPT_RETENTION_DAYS', 180),
        ],

        /*
        | Rate limits, in requests per minute. Applied by the limiters
        | registered in AppServiceProvider.
        */
        'rate_limits' => [
            'api' => (int) env('RATE_LIMIT_API', 60),
            'auth' => (int) env('RATE_LIMIT_AUTH', 5),
            'search' => (int) env('RATE_LIMIT_SEARCH', 30),
            'panel' => (int) env('RATE_LIMIT_PANEL', 120),
            // Public storefront reads — anonymous browsing traffic, generous and
            // IP-keyed (ADR-034).
            'storefront' => (int) env('RATE_LIMIT_STOREFRONT', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Business modules register themselves here as they are built. Empty in
    | Sprint 0 by design — see app/Modules/README.md.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Organization
    |--------------------------------------------------------------------------
    |
    | `default_store_limit` is the system-wide fallback for how many Stores an
    | organization may open (ADR-028), used when the org has neither a
    | per-organization override nor a plan. null = unlimited. The resolution
    | order is override → plan → this default.
    |
    | @see App\Modules\Organization\Domain\Models\Organization::effectiveStoreLimit()
    |
    */

    'organization' => [
        'default_store_limit' => is_numeric(env('ORGANIZATION_DEFAULT_STORE_LIMIT', 1))
            ? (int) env('ORGANIZATION_DEFAULT_STORE_LIMIT', 1)
            : null,

        // How long a membership invitation stays acceptable (ADR-031). Long
        // enough for a recipient to register first if they must; short enough
        // that a leaked-but-unused link does not live forever.
        'invitation_expiry_days' => (int) env('ORGANIZATION_INVITATION_EXPIRY_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Stores are addressed by platform path — `/store/{slug}` (ADR-035); there is
    | no per-store domain in v1. `number_prefix` is the human-facing store-code
    | prefix (`ST-XXXXXXXX`), config not hardcoded (ADR-025).
    |
    | @see App\Modules\Store\Infrastructure\Generators\RandomStoreNumberGenerator
    |
    */

    'store' => [
        'number_prefix' => env('STORE_NUMBER_PREFIX', 'ST'),

        // The public path segments a storefront is reachable under (ADR-035).
        // The first is the canonical segment used to build canonical URLs. Each
        // becomes an unauthenticated `GET /{segment}/{slug}` route.
        'public_path_segments' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STORE_PUBLIC_PATH_SEGMENTS', 'store,magaza')),
        ))),
    ],

    'modules' => [
        //
    ],

];
