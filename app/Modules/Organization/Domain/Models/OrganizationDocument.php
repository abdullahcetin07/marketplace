<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Models;

use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Shared\Traits\HasMedia;
use App\Shared\Traits\HasUuid;
use Database\Modules\Organization\Factories\OrganizationDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A KYC / legal document uploaded by an organization.
 *
 * PRIVATE BY CONSTRUCTION. The file lives in the shared `documents` media
 * collection, which is bound to the **private disk** (`App\Shared\Traits\HasMedia`);
 * it is never publicly readable. Access is a short-lived signed URL only
 * (`temporaryUrl()`), scoped by the document policy.
 *
 * Auditable, so an admin's approve/reject decision (and its reason) is a
 * forensic record.
 *
 * The trait and the Spatie interface share the short name `HasMedia`; the
 * interface is imported as `HasMediaContract` to keep them distinct.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property OrganizationDocumentType $type
 * @property OrganizationDocumentStatus $status
 * @property int|null $reviewed_by
 * @property string|null $review_notes
 *
 * @see docs/modules/Organization.md §2.4
 */
final class OrganizationDocument extends Model implements HasMediaContract
{
    /** @use HasFactory<OrganizationDocumentFactory> */
    use HasFactory;

    use Auditable;
    use HasMedia;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'organization_documents';

    protected $fillable = [
        'organization_id',
        'type',
        'status',
        'reviewed_by',
        'review_notes',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function file(): ?Media
    {
        return $this->getFirstMedia('documents');
    }

    /**
     * A short-lived signed URL to the private file, or null if none is attached.
     * The TTL is the platform's media default; the policy decides WHO may call
     * this at all.
     */
    public function temporaryUrl(): ?string
    {
        $media = $this->file();

        if ($media === null) {
            return null;
        }

        $ttl = (int) config('marketplace.media.signed_url_ttl', 300);

        return $media->getTemporaryUrl(now()->addSeconds($ttl));
    }

    public function isPending(): bool
    {
        return $this->status === OrganizationDocumentStatus::Pending;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationDocumentType::class,
            'status' => OrganizationDocumentStatus::class,
        ];
    }
}
