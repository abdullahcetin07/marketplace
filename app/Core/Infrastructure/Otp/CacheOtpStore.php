<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Otp;

use App\Core\Domain\Contracts\OtpStoreContract;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache-backed OTP store — the generic primitive of ADR-026.
 *
 * WHY CACHE, NOT A TABLE: an OTP lives for minutes and is used once. Persisting
 * it would mean a row that exists only to be deleted, plus a cleanup job. The
 * cache's TTL does the expiry for free, and losing the store on a cache flush
 * is harmless — the user simply requests a new code.
 *
 * BRUTE-FORCE POSTURE: a 6-digit code is 1-in-a-million. That is thin on its
 * own, so three things back it: a short TTL, single-use consumption, and the
 * per-IP/per-account rate limit on whatever route requests the code. An
 * attacker gets a handful of guesses inside the window, not a million.
 *
 * @see App\Core\Domain\Contracts\OtpStoreContract
 */
final class CacheOtpStore implements OtpStoreContract
{
    private const string PREFIX = 'otp:';

    public function __construct(private readonly CacheRepository $cache) {}

    public function issue(string $identifier, int $ttlSeconds, int $length = 6): string
    {
        $max = (10 ** max(1, $length)) - 1;

        // Zero-padded to a fixed width, so a leading-zero code renders in full.
        $code = str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);

        $this->cache->put($this->key($identifier), $code, $ttlSeconds);

        return $code;
    }

    public function verify(string $identifier, string $code): bool
    {
        $stored = $this->cache->get($this->key($identifier));

        if (! is_string($stored)) {
            return false;
        }

        // Constant-time compare, then consume on success — single-use.
        if (! hash_equals($stored, trim($code))) {
            return false;
        }

        $this->forget($identifier);

        return true;
    }

    public function has(string $identifier): bool
    {
        return $this->cache->has($this->key($identifier));
    }

    public function forget(string $identifier): void
    {
        $this->cache->forget($this->key($identifier));
    }

    private function key(string $identifier): string
    {
        // Hash the identifier so an email address never lands in a cache key.
        return self::PREFIX.hash('sha256', $identifier);
    }
}
