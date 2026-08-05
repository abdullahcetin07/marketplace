<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Console;

use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Console\Command;

/**
 * Answer "why is the payment integration not working" without a debugger.
 *
 * WRITTEN AFTER A DAY SPENT ANSWERING IT BY HAND. Three separate failures had
 * the same symptom — the money is taken and the order stays `awaiting_payment` —
 * and each needed a different place to look: the environment, PayTR's own
 * merchant panel, and the nginx access log. None of the three is visible from
 * the application log, which is why the integration could be wrong for hours
 * with a green suite and a silent server.
 *
 * IT PRINTS NO SECRET. `merchant_key` and `merchant_salt` are what make the
 * callback hash unforgeable — anyone holding them can post a "payment
 * succeeded" this platform would believe — so this reports only whether they are
 * PRESENT and how long they are. A diagnostic that leaks the credential it is
 * diagnosing is worse than no diagnostic.
 *
 * THE MOST USEFUL LINE IS THE ONE IT CANNOT CHECK: the notification URL lives in
 * PayTR's panel, not here (the iFrame API has no parameter for it), so the
 * command prints the value the panel must contain and where to paste it.
 *
 * @see docs/deployment.md — "PayTR — one setting lives in THEIR panel"
 * @see docs/modules/Payment.md §3
 */
final class DiagnosePaymentCommand extends Command
{
    protected $signature = 'payment:diagnose';

    protected $description = 'Report the payment gateway configuration and any payments stuck awaiting a callback';

    public function handle(): int
    {
        $this->components->info('Gateway');

        $this->line('  driver     : '.(string) config('payment.gateway'));
        $this->line('  test mode  : '.(config('payment.paytr.test_mode') ? '<fg=yellow>ON (no real money)</>' : '<fg=red>OFF (live)</>'));

        $this->newLine();
        $this->components->info('Credentials (presence only — never the values)');

        foreach (['merchant_id', 'merchant_key', 'merchant_salt'] as $key) {
            $value = (string) config("payment.paytr.{$key}");

            $this->line(sprintf(
                '  %-13s: %s',
                $key,
                $value === ''
                    ? '<fg=red>MISSING — check .env, then php artisan config:clear</>'
                    : '<fg=green>set</> ('.strlen($value).' chars)',
            ));
        }

        $this->newLine();
        $this->components->info('URLs');

        $this->line('  get-token  : '.(string) config('payment.paytr.token_url'));
        $this->line('  buyer OK   : '.(string) config('payment.result_urls.success'));
        $this->line('  buyer fail : '.(string) config('payment.result_urls.failure'));

        $this->newLine();
        $this->components->warn('Bildirim URL — set this in PayTR\'s panel, it is NOT sent by the API');
        $this->line('  <fg=cyan>'.(string) config('payment.paytr.notification_url').'</>');
        $this->line('  PayTR Mağaza Paneli → Destek & Kurulum → Ayarlar → Bildirim URL');
        $this->line('  <fg=gray>Wrong value = money taken, order never confirmed, and nothing in our log.</>');
        $this->line('  <fg=gray>Check for its POSTs:  grep -c "payments/paytr/callback" /var/log/nginx/*access.log</>');

        $this->newLine();
        $this->components->info('Payments awaiting a callback');

        /*
        | THE SYMPTOM, COUNTED. A pending Payment is normal for a few minutes —
        | the buyer is on the iframe — and abnormal for a day. Several old ones is
        | the signature of a callback that never arrives.
        */
        $pending = Payment::query()->where('status', PaymentStatus::Pending->value)->count();
        $oldest = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->oldest('id')
            ->value('created_at');

        $this->line('  pending    : '.$pending);
        $this->line('  oldest     : '.($oldest === null ? '—' : $oldest->diffForHumans()));

        if ($oldest !== null && $oldest->diffInHours(absolute: true) >= 1) {
            $this->newLine();
            $this->components->error('A payment has been pending for over an hour — the callback is not reaching this application.');
        }

        return self::SUCCESS;
    }
}
