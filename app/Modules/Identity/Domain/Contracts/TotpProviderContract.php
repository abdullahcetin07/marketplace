<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

/**
 * The TOTP algorithm, behind a port.
 *
 * WHY: no service or controller may depend on `Google2FA` directly (ADR-026,
 * applying §13.1). Swapping the library — or moving to WebAuthn-style
 * verification later — must not touch `TwoFactorService`. Everything the
 * service needs from a TOTP implementation is these three operations.
 *
 * @see App\Modules\Identity\Infrastructure\Totp\Google2FaTotpProvider
 */
interface TotpProviderContract
{
    /**
     * A fresh base32 shared secret.
     */
    public function generateSecret(): string;

    /**
     * The `otpauth://` URI an authenticator app scans. The issuer and account
     * let a user tell several entries apart in their app.
     */
    public function provisioningUri(string $issuer, string $accountName, string $secret): string;

    /**
     * Whether a code is currently valid for a secret.
     *
     * `$window` is the ±30-second-step drift tolerance; null uses the
     * implementation's default.
     */
    public function verify(string $secret, string $code, ?int $window = null): bool;
}
