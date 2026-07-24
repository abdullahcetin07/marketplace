<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Illuminate\Http\Response;

/**
 * An invitation could not be issued or accepted (ADR-031).
 *
 * All expected domain refusals — a stale link, a mismatched recipient, an
 * already-joined member — never a 500. The messages are deliberately vague to
 * the client about which invitation exists, so the endpoint is not an oracle.
 *
 * @see docs/modules/Organization.md §6
 */
final class InvitationException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * No pending, unexpired invitation matches the presented token — invalid,
     * already used, cancelled or expired. One message for all, so the endpoint
     * cannot be used to probe which tokens exist.
     */
    public static function notAcceptable(): self
    {
        return (self::make('This invitation is no longer valid.')
            ->withContext(['reason' => 'not_acceptable']))
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The authenticated account is not the invited recipient.
     */
    public static function emailMismatch(): self
    {
        return self::make('This invitation was sent to a different email address.')
            ->withContext(['reason' => 'email_mismatch']);
    }

    /**
     * The recipient is already a member of the organization.
     */
    public static function alreadyMember(): self
    {
        return self::make('You are already a member of this organization.')
            ->withContext(['reason' => 'already_member']);
    }

    /**
     * The Owner role cannot be invited — ownership is reached only by transfer.
     */
    public static function cannotInviteOwner(): self
    {
        return self::make('The owner role cannot be granted by invitation.')
            ->withContext(['reason' => 'cannot_invite_owner']);
    }
}
