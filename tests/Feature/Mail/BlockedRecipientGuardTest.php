<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| Undeliverable recipients never reach a transport
|--------------------------------------------------------------------------
|
| The failure being prevented is not "we emailed a fake address". It is what
| the fake address did to everybody else: Resend rejected `@example.com`, SES
| answered 403 behind it, and the round-robin then marked BOTH transports dead
| for `retry_after` seconds — so the next real notifications in that burst
| died with "No transports found" without either provider being asked.
|
| The assertions below are therefore about blast radius: the bad message stops,
| the good one in the same breath does not, and nothing throws (a queued
| notification that raised here would sit in `failed_jobs` retrying an address
| that will never exist).
|
*/

beforeEach(function (): void {
    config()->set('mail.default', 'array');
    config()->set('mail.blocked_recipient_domains', ['example.com', 'mailinator.com']);

    mailArrayTransport()->flush();
});

function mailArrayTransport(): ArrayTransport
{
    $transport = Mail::mailer('array')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    assert($transport instanceof ArrayTransport);

    return $transport;
}

function sentMessages(): int
{
    return mailArrayTransport()->messages()->count();
}

it('drops a message addressed only to a blocked domain', function (): void {
    $log = Log::spy();

    Mail::mailer('array')->raw('body', function ($message): void {
        $message->to('nobody@example.com')->subject('Doğrulama');
    });

    expect(sentMessages())->toBe(0);

    $log->shouldHaveReceived('info', [
        'Mail recipient blocked',
        Mockery::on(static fn (array $context): bool => $context['cancelled'] === true
            && $context['dropped'] === ['nobody@example.com']),
    ]);
});

it('sends to a real address in the same configuration', function (): void {
    Mail::mailer('array')->raw('body', function ($message): void {
        $message->to('musteri@raftabul.com')->subject('Sipariş');
    });

    expect(sentMessages())->toBe(1);
});

it('strips only the blocked copy when a real recipient remains', function (): void {
    /*
    | Cancelling the whole message because one CC is undeliverable would turn a
    | cosmetic problem into a lost notification for the person who matters.
    */
    Mail::mailer('array')->raw('body', function ($message): void {
        $message->to('musteri@raftabul.com')
            ->cc('tester@example.com')
            ->subject('Sipariş');
    });

    $sent = mailArrayTransport()->messages();

    expect($sent)->toHaveCount(1);

    $email = $sent->first()?->getOriginalMessage();
    assert($email instanceof Email);

    $recipients = array_map(
        static fn (Address $address): string => $address->getAddress(),
        $email->getTo(),
    );

    expect($recipients)->toBe(['musteri@raftabul.com'])
        ->and($email->getCc())->toBe([]);
});

it('is case- and subdomain-honest about what it blocks', function (): void {
    /*
    | `NOBODY@EXAMPLE.COM` is the same dead address in a different case, while
    | `notexample.com` is a different domain that merely ends the same way —
    | a naive `str_contains` would block the second and deliver nothing to a
    | real customer.
    */
    Mail::mailer('array')->raw('body', function ($message): void {
        $message->to('NOBODY@EXAMPLE.COM')->subject('a');
    });

    expect(sentMessages())->toBe(0);

    Mail::mailer('array')->raw('body', function ($message): void {
        $message->to('someone@notexample.com')->subject('b');
    });

    expect(sentMessages())->toBe(1);
});

it('does nothing when the list is empty', function (): void {
    config()->set('mail.blocked_recipient_domains', []);

    Mail::mailer('array')->raw('body', function ($message): void {
        $message->to('nobody@example.com')->subject('a');
    });

    expect(sentMessages())->toBe(1);
});

it('keeps the failover dead-window short enough to survive a burst', function (): void {
    /*
    | This number is a blast radius, not a backoff: it is how long ONE rejected
    | message keeps a working provider switched off for every other message.
    */
    expect(config('mail.mailers.failover.retry_after'))->toBe(5);
});
