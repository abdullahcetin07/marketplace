<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;

/**
 * An upload failed validation.
 *
 * Not reportable: a user picking the wrong file is expected behaviour, not an
 * incident.
 */
final class MediaRejected extends BaseException
{
    protected int $status = 422;

    public static function unsupportedType(string $mimeType): self
    {
        $exception = new self;
        $exception->errorCode = 'media_type_not_allowed';

        // The MIME type is safe to echo — the user supplied the file.
        return $exception->withContext(['mime_type' => $mimeType]);
    }

    public static function tooLarge(int $size, int $max): self
    {
        $exception = new self;
        $exception->errorCode = 'media_too_large';

        return $exception->withContext([
            'size_mb' => round($size / 1048576, 2),
            'max_mb' => round($max / 1048576, 2),
        ]);
    }
}
