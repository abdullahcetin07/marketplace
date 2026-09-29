<?php

declare(strict_types=1);

namespace App\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Catches the handful of domains nobody ever means to type.
 *
 * **A CUSTOMER REGISTERED AS `…@gmail.com.tr`, PAID FOR AN ORDER AND HEARD
 * NOTHING SINCE** (2026-09-24). No confirmation, no shipping notice, no way to
 * reset a password — the domain does not exist, so every message bounced into
 * silence. They are a real buyer the platform cannot reach.
 *
 * **WHY NOT `email:dns`.** It was tried and removed on purpose: a DNS lookup on
 * every signup, and a valid address refused whenever its DNS was momentarily
 * unreachable (`RegisterRequest`). This rule keeps that decision intact — it
 * makes no network call at all.
 *
 * **AND DNS WOULD MISS THE DANGEROUS HALF ANYWAY.** Measured the same day:
 * `gmai.com`, `gamil.com`, `gmail.co` and `iclould.com` all hold live MX
 * records. They are squatted, they accept mail, and a confirmation sent there
 * hands a stranger the customer's name, address and phone number. `email:dns`
 * waves every one of them through. Near-miss, not resolvability, is the signal.
 *
 * **THE LIST IS CURATED, NOT COMPUTED.** Edit distance would flag
 * `yahoo.com.tr`, `hotmail.com.tr`, `outlook.com.tr` and `yandex.com.tr` —
 * regional domains that genuinely carry mail, each verified before being left
 * out. Every entry below is a deliberate judgement about a domain no one
 * intends, and the message names what we think they meant so a real owner of
 * one can say otherwise.
 */
final class SuspectedEmailTypo implements ValidationRule
{
    /**
     * Domain typed => domain almost certainly meant.
     *
     * @var array<string, string>
     */
    private const TYPOS = [
        // gmail.com
        'gmail.com.tr' => 'gmail.com',
        'gmial.com' => 'gmail.com',
        'gmai.com' => 'gmail.com',
        'gamil.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmail.cim' => 'gmail.com',
        'gmail.cm' => 'gmail.com',

        // hotmail.com
        'hotmial.com' => 'hotmail.com',
        'hotmai.com' => 'hotmail.com',
        'hotmal.com' => 'hotmail.com',
        'hotmaill.com' => 'hotmail.com',
        'hotmail.con' => 'hotmail.com',
        'hotmail.cm' => 'hotmail.com',

        // yahoo.com — note yahoo.com.tr is REAL and deliberately absent
        'yaho.com' => 'yahoo.com',
        'yahoo.con' => 'yahoo.com',
        'yahooo.com' => 'yahoo.com',

        // outlook.com — outlook.com.tr is real and deliberately absent
        'outlok.com' => 'outlook.com',
        'outlook.con' => 'outlook.com',
        'outllok.com' => 'outlook.com',

        // icloud.com
        'iclould.com' => 'icloud.com',
        'icloud.con' => 'icloud.com',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $suggestion = self::suggest($value);

        if ($suggestion !== null) {
            $fail(__('validation.email_typo', ['suggestion' => $suggestion]))->translate();
        }
    }

    /**
     * The address they probably meant, or null when this one looks fine.
     *
     * Returns the WHOLE address rather than the domain: "bunu mu demek
     * istediniz" is only useful if it shows the thing they can copy.
     */
    public static function suggest(string $email): ?string
    {
        $at = mb_strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $local = mb_substr($email, 0, $at);
        $domain = mb_strtolower(trim(mb_substr($email, $at + 1)));

        if (! array_key_exists($domain, self::TYPOS)) {
            return null;
        }

        return $local.'@'.self::TYPOS[$domain];
    }
}
