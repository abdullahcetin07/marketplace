<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * A short-lived, single-use one-time-password store.
 *
 * A GENERIC PRIMITIVE, IN CORE (ADR-026). It operates on an opaque identifier
 * and carries no business meaning, so it lives here rather than in the module
 * that first needed it. That is what lets every future consumer —
 * email-verification fallback, sensitive-action confirmation, store-ownership
 * verification, organization invitations, high-risk-operation confirmation —
 * reach it without importing Identity.
 *
 * The Domain layer never calls `cache()` (ADR-019); this port is how a caller
 * reaches the cache-backed implementation.
 *
 * @see App\Core\Infrastructure\Otp\CacheOtpStore
 */
interface OtpStoreContract
{
    /**
     * Generate a fresh numeric code for an identifier, store it, and return the
     * plaintext. Overwrites any code already outstanding for that identifier —
     * issuing a new one invalidates the previous, so codes cannot be
     * stockpiled.
     */
    public function issue(string $identifier, int $ttlSeconds, int $length = 6): string;

    /**
     * Verify a code and consume it on a match.
     *
     * Single-use: a correct code is deleted, so it cannot be replayed. Returns
     * false on mismatch, absence or expiry — indistinguishably, so a caller
     * learns nothing beyond pass/fail.
     */
    public function verify(string $identifier, string $code): bool;

    public function has(string $identifier): bool;

    public function forget(string $identifier): void;
}
