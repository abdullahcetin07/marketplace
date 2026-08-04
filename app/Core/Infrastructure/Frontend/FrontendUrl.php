<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Frontend;

/**
 * Composes links into the storefront from configuration.
 *
 * ADR-025: the backend never hardcodes a frontend URL, and never returns a
 * credential in an API response so the frontend can build one itself. Both
 * problems are solved here — the path is a config template, the token is
 * substituted into it, and the finished link goes out by email.
 *
 * Infrastructure, not Domain: it reads config and produces an external URL.
 *
 * @see config/marketplace.php
 */
final class FrontendUrl
{
    /**
     * Password reset link.
     *
     * The email is carried as a query parameter because Laravel's password
     * broker verifies the token against it — the token alone is not a
     * complete credential.
     */
    public static function passwordReset(string $token, string $email): string
    {
        return self::compose(
            (string) config('marketplace.frontend.password_reset_path', '/reset-password/{token}'),
            ['{token}' => $token],
        ).'?email='.urlencode($email);
    }

    /**
     * Email verification link.
     *
     * `{id}` is the user's UUID — never the internal id, which does not leave
     * the application. `{hash}` is `sha1(email)`, which proves the link matches
     * the account but is guessable on its own.
     *
     * The real credential is the SIGNATURE in `$signedQuery` (`expires` +
     * `signature`). It is computed over the API callback URL, so the frontend
     * appends it verbatim when it calls back and `hasValidSignature()` still
     * holds. That signature travels by email (ADR-025), never in a response.
     *
     * @param array<string, mixed> $signedQuery
     */
    public static function emailVerification(string $uuid, string $hash, array $signedQuery = []): string
    {
        $url = self::compose(
            (string) config('marketplace.frontend.email_verify_path', '/verify-email/{id}/{hash}'),
            ['{id}' => $uuid, '{hash}' => $hash],
        );

        return $signedQuery === [] ? $url : $url.'?'.http_build_query($signedQuery);
    }

    /**
     * Any configured path, with placeholders substituted.
     *
     * @param array<string, string> $replacements
     */
    public static function compose(string $path, array $replacements = []): string
    {
        $base = rtrim((string) config('marketplace.frontend.url', ''), '/');

        $path = strtr($path, array_map(
            // Placeholder values are tokens and UUIDs, but encode anyway —
            // this method is public and a future caller may pass free text.
            static fn (string $value): string => rawurlencode($value),
            $replacements,
        ));

        return $base.'/'.ltrim($path, '/');
    }
}
