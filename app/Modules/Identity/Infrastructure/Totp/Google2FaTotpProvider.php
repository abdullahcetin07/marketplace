<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Totp;

use App\Modules\Identity\Domain\Contracts\TotpProviderContract;
use PragmaRX\Google2FA\Google2FA;

/**
 * `pragmarx/google2fa` behind the TOTP port.
 *
 * This is the ONLY class in the module that names `Google2FA`. Replacing the
 * library is a change here and a binding swap — no business logic moves.
 *
 * WHY A LIBRARY AND NOT OUR OWN TOTP: verification is short enough to look easy
 * and has silent failure modes — a wrong time-window comparison accepts codes
 * it should reject, and nothing surfaces it until an incident.
 *
 * @see App\Modules\Identity\Domain\Contracts\TotpProviderContract
 */
final class Google2FaTotpProvider implements TotpProviderContract
{
    private const int DEFAULT_WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        $length = (int) config('marketplace.security.two_factor.secret_length', 32);

        return $this->google2fa->generateSecretKey($length);
    }

    public function provisioningUri(string $issuer, string $accountName, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $accountName, $secret);
    }

    public function verify(string $secret, string $code, ?int $window = null): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $code, $window ?? self::DEFAULT_WINDOW);
    }
}
