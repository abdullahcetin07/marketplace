<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

/**
 * The port an SMS gateway implements.
 *
 * Declared in Sprint 1 with no implementation, deliberately. Having the
 * contract now means notifications can be written against it and tested with a
 * fake, and choosing a provider later is a binding in a service provider
 * rather than a refactor.
 *
 * @see App\Modules\Notification\Infrastructure\Channels\SmsChannel
 */
interface SmsProvider
{
    /**
     * Deliver one message.
     *
     * @param  string  $to  E.164 number, e.g. +905551234567
     * @return string  provider message id, for delivery-receipt reconciliation
     *
     * @throws \App\Core\Domain\Exceptions\BaseException on permanent failure
     */
    public function send(string $to, string $message): string;

    /**
     * Remaining credit or quota, when the provider exposes it. Null when not
     * supported — surfaced on the admin dashboard so an operator finds out
     * before messages start failing, not after.
     */
    public function balance(): ?int;
}
