<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Drops mail addressed to domains that cannot receive it, before a transport
 * is ever asked to send it.
 *
 * **This exists because a rejected recipient is not a local failure.** The
 * failover chain (ADR-089) marks a transport that throws as dead for
 * `mail.mailers.failover.retry_after` seconds. One `@example.com` address left
 * over from storefront testing made Resend reject the message, SES answered
 * 403 behind it, and the next real notifications in that window died with "No
 * transports found" — mail nobody was asked to send, lost to an address nobody
 * was ever going to read. Observed in production on 2026-08-24.
 *
 * So the guard is not politeness about test data. It keeps a message that
 * CANNOT succeed away from the thing that other messages depend on.
 *
 * **It removes recipients, and only cancels when none are left.** A message to
 * a real customer that happens to CC a test address still goes out, minus the
 * CC. Cancelling the whole message on one bad copy would turn a cosmetic
 * problem into a lost notification.
 *
 * **It never throws.** A queued notification that raises here would land in
 * `failed_jobs` and be retried forever against an address that will never
 * exist. Suppression is logged at info and the job completes.
 *
 * The API surface is untouched: this drops a SEND, not a response. The
 * password-reset endpoint still answers the same way for every address
 * (ADR-025), so nothing here becomes an enumeration oracle.
 *
 * @see config/mail.php — `blocked_recipient_domains`
 * @see \App\Console\Commands\PurgeTestAccountsCommand — same list, other half
 */
final class BlockedRecipientGuard
{
    /**
     * @return bool `false` cancels the send — Laravel dispatches this event
     *              with `until()`, so a false return stops the mailer.
     */
    public function handle(MessageSending $event): bool
    {
        $blocked = $this->blockedDomains();

        if ($blocked === []) {
            return true;
        }

        $message = $event->message;

        $dropped = [];
        $kept = [];

        foreach (['To', 'Cc', 'Bcc'] as $header) {
            $addresses = $this->addresses($message, $header);

            if ($addresses === []) {
                continue;
            }

            $keptHere = [];

            foreach ($addresses as $address) {
                if ($this->isBlocked($address, $blocked)) {
                    $dropped[] = $address->getAddress();

                    continue;
                }

                $keptHere[] = $address;
                $kept[] = $address->getAddress();
            }

            if (count($keptHere) !== count($addresses)) {
                $this->replace($message, $header, $keptHere);
            }
        }

        if ($dropped === []) {
            return true;
        }

        Log::info('Mail recipient blocked', [
            'dropped' => $dropped,
            'remaining' => $kept,
            'subject' => $message->getSubject(),
            // Stated so the log line answers the only question worth asking of
            // it: did somebody who exists still get this message?
            'cancelled' => $kept === [],
        ]);

        return $kept !== [];
    }

    /**
     * @return array<int, string>
     */
    private function blockedDomains(): array
    {
        /** @var array<int, string> $domains */
        $domains = (array) config('mail.blocked_recipient_domains', []);

        return array_values(array_filter(array_map(
            static fn (mixed $domain): string => mb_strtolower(trim((string) $domain)),
            $domains,
        )));
    }

    /**
     * @param array<int, string> $blocked
     */
    private function isBlocked(Address $address, array $blocked): bool
    {
        $at = mb_strrpos($address->getAddress(), '@');

        if ($at === false) {
            return false;
        }

        $domain = mb_strtolower(mb_substr($address->getAddress(), $at + 1));

        return in_array($domain, $blocked, true);
    }

    /**
     * @return array<int, Address>
     */
    private function addresses(Email $message, string $header): array
    {
        return match ($header) {
            'To' => $message->getTo(),
            'Cc' => $message->getCc(),
            default => $message->getBcc(),
        };
    }

    /**
     * Symfony has no "remove one recipient": the setters replace the whole
     * header, and passing none leaves an empty header rather than no header —
     * which some transports serialise as `To: `. Dropping the header outright
     * is the only clean way to express "there is nobody here any more".
     *
     * @param array<int, Address> $kept
     */
    private function replace(Email $message, string $header, array $kept): void
    {
        if ($kept === []) {
            $message->getHeaders()->remove($header);

            return;
        }

        match ($header) {
            'To' => $message->to(...$kept),
            'Cc' => $message->cc(...$kept),
            default => $message->bcc(...$kept),
        };
    }
}
