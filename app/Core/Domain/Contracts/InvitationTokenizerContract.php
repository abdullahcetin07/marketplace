<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * Issues and verifies invitation tokens (ADR-031).
 *
 * The platform stores ONLY the hash of a token. The raw token is generated
 * here, handed to the caller to email once (out-of-band, ADR-025), and never
 * persisted. Verification hashes the presented token and compares — so the
 * hash must be **deterministic** (unlike a password hash), which is what lets a
 * consumer look an invitation up by `token_hash` in one indexed query.
 *
 * A port, so the algorithm can change without touching a single consumer.
 *
 * @see App\Core\Infrastructure\Invitations\Sha256InvitationTokenizer
 */
interface InvitationTokenizerContract
{
    /**
     * A fresh, high-entropy raw token. Show it to no one but the recipient, via
     * email; do not log it, return it in an API, or store it.
     */
    public function generate(): string;

    /**
     * The deterministic hash of a raw token — the only form the database holds.
     */
    public function hash(string $rawToken): string;

    /**
     * Whether a presented raw token matches a stored hash. Timing-safe.
     */
    public function verify(string $rawToken, string $tokenHash): bool;
}
