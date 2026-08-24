<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Transactional mail goes over HTTP, not SMTP (ADR-089)
|--------------------------------------------------------------------------
|
| This host blocks outbound SMTP: ports 25/465/587/2465/2587 time out to three
| different providers while 22/53/443 are open, `ufw` is ACCEPT on output and
| there are no local rules. It is an upstream block, so no credential and no
| configuration fixes it — which is why every mail path on this platform is an
| HTTP API.
|
| SES was that path until AWS refused production access, leaving it able to reach
| only verified addresses. Resend is the one that delivers; SES stays behind it in
| the failover chain, costing nothing while it cannot deliver and becoming a real
| second provider the day the account is approved.
|
| These assertions are cheap and they guard the two mistakes that would put the
| platform back to silently undelivered mail: `smtp` creeping back into the chain,
| and the Resend key being read from only one of its two documented env names.
|
*/

it('resolves the resend transport', function (): void {
    config()->set('services.resend.key', 'test-key');

    expect(Mail::mailer('resend'))->not->toBeNull();
});

it('keeps smtp out of the failover chain', function (): void {
    /*
    | A dead port in the chain is not a harmless extra attempt: the failover
    | transport tries each mailer in turn, so every message would burn a full
    | connection timeout on a port that cannot open before reaching the one that
    | works. A fast failure became a slow one, per message.
    */
    $chain = config('mail.mailers.failover.mailers');

    expect($chain)->toBe(['resend', 'ses'])
        ->and($chain)->not->toContain('smtp');
});

it('reads the resend key under either documented name', function (): void {
    /*
    | `resend/resend-laravel` reads `RESEND_API_KEY` through its own merged
    | config; Laravel documents `RESEND_KEY` through `services.resend.key`. An
    | owner typing the name from either set of docs must get a working mailer
    | rather than "The Resend API key is missing".
    */
    $config = require base_path('config/services.php');

    putenv('RESEND_KEY');
    putenv('RESEND_API_KEY=from-api-key-name');

    expect((require base_path('config/services.php'))['resend']['key'])
        ->toBe('from-api-key-name');

    putenv('RESEND_KEY=from-key-name');

    expect((require base_path('config/services.php'))['resend']['key'])
        ->toBe('from-key-name');

    putenv('RESEND_KEY');
    putenv('RESEND_API_KEY');

    expect($config)->toBeArray();
});
