<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Models;

use App\Models\User;
use App\Modules\Audit\Domain\Concerns\Auditable;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Shared\Traits\HasUuid;
use Database\Modules\Organization\Factories\OrganizationMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user's membership of one organization, with a role (ADR-030).
 *
 * A user may hold MANY of these — one per organization they belong to — each
 * with its own role. The Owner is the membership whose role is Owner; exactly
 * one exists per organization and it cannot be removed (ADR-029).
 *
 * Auditable: who joined, whose role changed, who was removed is forensic
 * evidence.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $user_id
 * @property OrganizationRole $role
 * @property OrganizationMemberStatus $status
 * @property int|null $invited_by
 * @property \Illuminate\Support\Carbon|null $joined_at
 *
 * @see docs/modules/Organization.md §2.2
 */
final class OrganizationMember extends Model
{
    /** @use HasFactory<OrganizationMemberFactory> */
    use HasFactory;

    use Auditable;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'organization_members';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'status',
        'invited_by',
        'joined_at',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwner(): bool
    {
        return $this->role === OrganizationRole::Owner;
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationMemberStatus::Active;
    }

    /**
     * Whether this member holds a capability — the resolution the policies use.
     */
    public function can(\App\Modules\Organization\Domain\Enums\OrganizationCapability $capability): bool
    {
        return $this->isActive() && $this->role->allows($capability);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OrganizationMemberStatus::Active->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'status' => OrganizationMemberStatus::class,
            'joined_at' => 'datetime',
        ];
    }
}
