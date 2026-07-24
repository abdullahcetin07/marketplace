<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;

/**
 * The verification callback.
 *
 * AUTHORISATION IS THE SIGNATURE. There is no logged-in user — the person
 * clicking a link from their inbox may never have signed in. The signed URL
 * is the credential, and `hasValidSignature()` is the whole auth check:
 *
 *  - a tampered UUID or hash breaks the signature → 403
 *  - an expired link fails the `expires` check → 403
 *
 * Only a link that is authentic AND unexpired reaches the action, which then
 * matches the hash to the account.
 *
 * The `absolute` argument is true because the notification signed an absolute
 * URL over the API host, and the frontend calls back on that same host.
 */
final class VerifyEmailRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->hasValidSignature();
    }

    /**
     * Route params only — no body. Constraining them here means a malformed
     * UUID is a validation error, not an exception deeper in.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'hash' => ['required', 'string'],
        ];
    }

    public function uuid(): string
    {
        return (string) $this->route('uuid');
    }

    public function hash(): string
    {
        return (string) $this->route('hash');
    }
}
