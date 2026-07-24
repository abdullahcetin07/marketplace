<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Invitations;

use App\Core\Domain\Contracts\InvitationTokenizerContract;
use Illuminate\Support\Str;

/**
 * SHA-256 invitation tokenizer (ADR-031).
 *
 * WHY SHA-256 AND NOT BCRYPT: an invitation token is high-entropy (unlike a
 * user-chosen password), so it does not need a slow, salted hash to resist
 * guessing. It DOES need a **deterministic** hash, so a consumer can look the
 * invitation up by `token_hash` in one indexed query rather than iterating every
 * pending row. Comparison is timing-safe.
 *
 * @see App\Core\Domain\Contracts\InvitationTokenizerContract
 */
final class Sha256InvitationTokenizer implements InvitationTokenizerContract
{
    /**
     * 64 url-safe characters — ~380 bits of entropy, far beyond brute force.
     */
    public function generate(): string
    {
        return Str::random(64);
    }

    public function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function verify(string $rawToken, string $tokenHash): bool
    {
        return hash_equals($tokenHash, $this->hash($rawToken));
    }
}
