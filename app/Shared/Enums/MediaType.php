<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Broad classification of a stored file, derived from its MIME type.
 *
 * An enum rather than a lookup table: each case implies different processing
 * code (image conversions, document scanning), so a new case is a code change
 * by definition.
 *
 * @see App\Modules\Media\Domain\Models\MediaFile
 * @see docs/media.md
 */
enum MediaType: string
{
    use HasEnumHelpers;

    case Image = 'image';
    case Document = 'document';
    case Video = 'video';
    case Audio = 'audio';
    case Archive = 'archive';
    case Other = 'other';

    /**
     * Classify from a MIME type. Falls back to Other rather than throwing —
     * this runs on user uploads, where an unexpected type must not 500.
     */
    public static function fromMimeType(string $mimeType): self
    {
        $mimeType = mb_strtolower(trim($mimeType));

        return match (true) {
            str_starts_with($mimeType, 'image/') => self::Image,
            str_starts_with($mimeType, 'video/') => self::Video,
            str_starts_with($mimeType, 'audio/') => self::Audio,
            in_array($mimeType, [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'text/plain',
            ], true) => self::Document,
            in_array($mimeType, [
                'application/zip',
                'application/x-tar',
                'application/gzip',
                'application/x-7z-compressed',
            ], true) => self::Archive,
            default => self::Other,
        };
    }

    /**
     * MIME types accepted for this classification. The allow-list is the
     * security boundary — never accept a file because its extension looks
     * right.
     *
     * @return array<int, string>
     */
    public function acceptedMimeTypes(): array
    {
        return match ($this) {
            self::Image => ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'],
            self::Document => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'text/plain',
            ],
            self::Video => ['video/mp4', 'video/webm'],
            self::Audio => ['audio/mpeg', 'audio/ogg', 'audio/wav'],
            self::Archive => ['application/zip'],
            self::Other => [],
        };
    }

    /**
     * Per-type upload ceiling in bytes. A 10 MB cap on images is generous; the
     * same cap on video is useless, so the limit varies rather than forcing one
     * number to fit everything.
     */
    public function maxSize(): int
    {
        return match ($this) {
            self::Image => 10 * 1024 * 1024,
            self::Document => 20 * 1024 * 1024,
            self::Video => 200 * 1024 * 1024,
            self::Audio => 50 * 1024 * 1024,
            self::Archive => 50 * 1024 * 1024,
            self::Other => 5 * 1024 * 1024,
        };
    }

    /**
     * Whether OptimizeImageJob should run for this type.
     */
    public function isOptimisable(): bool
    {
        return $this === self::Image;
    }

    /**
     * Whether responsive variants are generated.
     */
    public function supportsConversions(): bool
    {
        return $this === self::Image;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Image => 'heroicon-o-photo',
            self::Document => 'heroicon-o-document-text',
            self::Video => 'heroicon-o-film',
            self::Audio => 'heroicon-o-musical-note',
            self::Archive => 'heroicon-o-archive-box',
            self::Other => 'heroicon-o-paper-clip',
        };
    }
}
