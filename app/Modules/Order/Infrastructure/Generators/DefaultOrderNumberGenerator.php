<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Generators;

use App\Modules\Order\Domain\Contracts\OrderNumberGeneratorContract;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Str;

/**
 * A dated prefix plus a random code, retried until free — e.g. `SP-260730-K7M4XB`.
 *
 * WHY NOT SEQUENTIAL, which is what most people reach for first: a sequential
 * order number tells every customer how many orders the platform has taken, and
 * lets anyone enumerate the range by counting. That is the same reasoning that
 * keeps internal ids out of URLs (non-negotiable #7), and it matters more here
 * because this number is PRINTED — on an invoice, in an email, in a screenshot
 * pasted into a support chat.
 *
 * A sequence also needs a counter, and a counter needs a lock: two checkouts a
 * millisecond apart would either serialise on it or collide. Random plus a
 * uniqueness retry has neither problem.
 *
 * THE DATE IS THERE FOR HUMANS. A support agent reading `SP-260730-…` knows
 * within a second when the order was placed, which is the first thing they ask.
 * It leaks nothing — the customer already knows when they ordered.
 *
 * THE ALPHABET DROPS 0/O AND 1/I, because this number is read aloud and typed
 * back at least once in its life, and those are the four characters people get
 * wrong.
 *
 * @see App\Modules\Order\Domain\Contracts\OrderNumberGeneratorContract
 */
final class DefaultOrderNumberGenerator implements OrderNumberGeneratorContract
{
    /**
     * Deliberately without 0, O, 1, I.
     */
    private const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const int CODE_LENGTH = 6;

    /**
     * A bounded retry. With 32^6 codes per day a collision is already
     * vanishingly unlikely, so exhausting this means something else is wrong and
     * looping forever would only hide it.
     */
    private const int MAX_ATTEMPTS = 20;

    public function generate(): string
    {
        $prefix = (string) config('order.numbers.prefix', 'SP');
        $date = now()->format('ymd');

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $number = "{$prefix}-{$date}-".$this->randomCode();

            if (! Order::query()->where('order_number', $number)->exists()) {
                return $number;
            }
        }

        /*
         * Not a domain exception: this is not something a customer did, and there
         * is no message that would help them. It is either an exhausted keyspace
         * or a broken random source, and both are incidents.
         */
        throw new \RuntimeException('Could not generate a unique order number.');
    }

    private function randomCode(): string
    {
        $code = '';
        $alphabet = self::ALPHABET;
        $max = mb_strlen($alphabet) - 1;

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
