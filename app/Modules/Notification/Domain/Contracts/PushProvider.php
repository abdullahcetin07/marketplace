<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

/**
 * The port a push gateway (FCM, APNs, OneSignal) implements.
 *
 * Declared with no implementation in Sprint 1. @see SmsProvider for the
 * reasoning.
 */
interface PushProvider
{
    /**
     * Deliver to one or more device tokens.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $payload  title, body, data
     * @return array<int, string>  tokens the provider rejected as invalid, so
     *                             the caller can prune them
     */
    public function send(array $tokens, array $payload): array;
}
