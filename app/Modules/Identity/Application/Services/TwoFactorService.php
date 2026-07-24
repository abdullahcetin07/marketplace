<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Models\User;
use App\Core\Domain\Contracts\OtpStoreContract;
use App\Modules\Identity\Domain\Contracts\TotpProviderContract;
use App\Modules\Identity\Domain\Events\TwoFactorDisabled;
use App\Modules\Identity\Domain\Events\TwoFactorEnabled;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TOTP enrolment and verification (RFC 6238), plus recovery codes and the
 * email-OTP fallback ordering (Q5). Driven by the API in TwoFactorController.
 *
 * THE ALGORITHM IS BEHIND A PORT (ADR-026): this service names
 * `TotpProviderContract`, never `Google2FA`. Swapping the library is a binding
 * change, not a rewrite.
 *
 * RECOVERY CODES ARE HASHED, with a configurable algorithm. They are
 * credentials: a database leak that hands over plaintext recovery codes is a
 * 2FA bypass for every enrolled user. They are shown to the user exactly once,
 * at generation. Count, length and hash come from configuration.
 *
 * @see App\Modules\Identity\Domain\Contracts\TotpProviderContract
 * @see docs/authentication.md §Two-factor
 */
final class TwoFactorService
{
    /**
     * Depends on the TOTP PORT, never on Google2FA directly (ADR-026), and on
     * the OTP store, a shared Core primitive. No ActivityLogger — Identity does
     * not import Activity; state changes are announced as events (§4).
     */
    public function __construct(
        private readonly TotpProviderContract $totp,
        private readonly OtpStoreContract $otp,
    ) {}

    /**
     * Begin enrolment: generate a secret and persist it UNCONFIRMED.
     *
     * The account is not protected until confirm() succeeds. Enabling 2FA on
     * the strength of a generated secret alone would lock out any user whose
     * authenticator failed to scan the QR code.
     */
    public function generateSecret(User $user): string
    {
        $secret = $this->totp->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     *
     * The issuer is the platform name so a user with several accounts can tell
     * the entries apart in their app.
     */
    public function provisioningUri(User $user): string
    {
        if ($user->two_factor_secret === null) {
            throw new \LogicException('Cannot build a provisioning URI before generateSecret().');
        }

        return $this->totp->provisioningUri(
            (string) config('app.name'),
            $user->email,
            $user->two_factor_secret,
        );
    }

    /**
     * Complete enrolment by proving the authenticator works.
     *
     * Returns the plaintext recovery codes — the ONLY time they are readable.
     * Callers must show them to the user immediately.
     *
     * @return array<int, string>|null  null when the code was wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || ! $this->verifyTotp($user, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => json_encode(
                array_map(fn (string $c): string => $this->hashRecoveryCode($c), $codes),
                JSON_THROW_ON_ERROR,
            ),
        ])->save();

        TwoFactorEnabled::dispatch($user->getKey(), $user->uuid, $user->type->value);

        return $codes;
    }

    /**
     * Verify a challenge during login — a TOTP code or a recovery code.
     *
     * Recovery codes are single-use and consumed on success.
     */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        // Ordered by cost and by how the user is meant to use them (Q5):
        //   1. TOTP        — the primary factor, one HMAC
        //   2. recovery    — bcrypt compare per stored code
        //   3. email OTP   — the fallback, one cache read
        if ($this->verifyTotp($user, $code)) {
            return true;
        }

        if ($this->consumeRecoveryCode($user, $code)) {
            return true;
        }

        return $this->otp->verify($this->otpIdentifier($user), $code);
    }

    /**
     * Issue an email-OTP fallback code for a user, returning the plaintext to
     * hand to the notification. The code is single-use and short-lived.
     */
    public function issueEmailOtp(User $user): string
    {
        return $this->otp->issue(
            $this->otpIdentifier($user),
            (int) config('marketplace.security.two_factor.email_otp_ttl_seconds', 300),
        );
    }

    /**
     * Per-user OTP key. Keyed on the immutable id, not the email, so a
     * mid-flow email change cannot orphan a pending code.
     */
    private function otpIdentifier(User $user): string
    {
        return 'email_otp:'.$user->getKey();
    }

    /**
     * Turn 2FA off. Requires an already-authenticated context — the caller is
     * responsible for having re-confirmed the password first.
     */
    public function disable(User $user, bool $byAdministrator = false): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        // `byAdministrator` matters: a support operator clearing someone's 2FA
        // is exactly what an attacker with helpdesk access does, and it must be
        // distinguishable in the timeline from the user doing it themselves.
        TwoFactorDisabled::dispatch($user->getKey(), $user->uuid, $byAdministrator, $user->type->value);
    }

    /**
     * Issue a fresh set, invalidating the old one. Returns plaintext once.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => json_encode(
                array_map(fn (string $c): string => $this->hashRecoveryCode($c), $codes),
                JSON_THROW_ON_ERROR,
            ),
        ])->save();

        return $codes;
    }

    /**
     * How many single-use codes remain. Surfaced in the UI so a user is warned
     * before they run out and lock themselves out.
     */
    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->storedRecoveryCodes($user));
    }

    /**
     * Whether this actor MUST enrol before proceeding.
     */
    public function isRequiredFor(User $user): bool
    {
        return $user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled();
    }

    private function verifyTotp(User $user, string $code): bool
    {
        // Input cleaning stays here (business concern); the algorithm is the
        // provider's. A code read off a screen with a space still validates.
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($user->two_factor_secret === null || ! ctype_digit($code)) {
            return false;
        }

        // `window` tolerates clock drift between server and phone, in
        // 30-second steps either side. 1 is the usual compromise; a larger
        // window widens the replay surface.
        return $this->totp->verify(
            $user->two_factor_secret,
            $code,
            (int) config('marketplace.security.two_factor.window', 1),
        );
    }

    /**
     * Check a recovery code and, on success, remove it.
     *
     * Iterates every stored hash rather than short-circuiting on a lookup,
     * because there is no way to index a bcrypt hash by its plaintext.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $stored = $this->storedRecoveryCodes($user);
        $code = trim($code);

        foreach ($stored as $index => $hash) {
            if (! Hash::check($code, $hash)) {
                continue;
            }

            unset($stored[$index]);

            $user->forceFill([
                'two_factor_recovery_codes' => json_encode(array_values($stored), JSON_THROW_ON_ERROR),
            ])->save();

            return true;
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function storedRecoveryCodes(User $user): array
    {
        if ($user->two_factor_recovery_codes === null) {
            return [];
        }

        try {
            /** @var array<int, string> $codes */
            $codes = json_decode($user->two_factor_recovery_codes, true, 512, JSON_THROW_ON_ERROR);

            return $codes;
        } catch (\JsonException) {
            // Written under a rotated APP_KEY and now undecryptable. Treating
            // it as empty is correct: the user falls back to TOTP or support.
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        // Count and per-segment length are configuration, not hardcoded
        // (ADR-026 / 002 §16).
        $count = (int) config('marketplace.security.two_factor.recovery_codes.count', 8);
        $segment = max(2, (int) config('marketplace.security.two_factor.recovery_codes.length', 5));

        return array_map(
            // Two segments joined with a dash, so a user reading one off paper
            // does not lose their place.
            static fn (): string => Str::upper(Str::random($segment).'-'.Str::random($segment)),
            range(1, max(1, $count)),
        );
    }

    /**
     * Hash a recovery code with the configured algorithm (ADR-026 / 002 §16).
     *
     * Only generation reads the config. Verification stays algorithm-agnostic:
     * `Hash::check()` detects the algorithm from the stored hash's prefix, so
     * changing the config does not break codes already issued under the old
     * one.
     */
    private function hashRecoveryCode(string $code): string
    {
        $algorithm = (string) config('marketplace.security.two_factor.recovery_codes.hash', 'bcrypt');

        return Hash::driver($algorithm)->make($code);
    }
}
