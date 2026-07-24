<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Models;

use App\Models\User;
use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Shared\Traits\HasMedia;
use App\Shared\Traits\HasUuid;
use Database\Modules\Organization\Factories\StoreOpeningRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;

/**
 * A request to open a Store (ADR-028).
 *
 * THE STORE DOES NOT LIVE HERE. This is only the request: an organization may
 * never create a Store directly, it submits this, an admin approves it, and the
 * future Store module — consuming `StoreOpeningApproved` — creates the actual
 * storefront. `created_store_uuid` is a UUID reference filled when that module
 * reports back, deliberately NOT a foreign key to a table this module does not
 * own.
 *
 * Auditable (an approval/rejection is a forensic decision) and HasMedia (the
 * proposed logo goes to the private/public collections like any image).
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $requested_by
 * @property StoreOpeningRequestStatus $status
 * @property string $store_name
 * @property string $slug
 * @property int|null $category_id
 * @property string|null $description
 * @property string|null $reason
 * @property string|null $admin_notes
 * @property int|null $reviewed_by
 * @property string|null $created_store_uuid
 *
 * @see docs/modules/Organization.md §7
 */
final class StoreOpeningRequest extends Model implements HasMediaContract
{
    /** @use HasFactory<StoreOpeningRequestFactory> */
    use HasFactory;

    use Auditable;
    use HasMedia;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'store_opening_requests';

    protected $fillable = [
        'organization_id',
        'requested_by',
        'status',
        'store_name',
        'slug',
        'category_id',
        'description',
        'reason',
        'admin_notes',
        'reviewed_by',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'created_store_uuid',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return $this->status === StoreOpeningRequestStatus::Pending;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', StoreOpeningRequestStatus::Approved->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StoreOpeningRequestStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}
