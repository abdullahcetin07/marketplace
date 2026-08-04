<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Models;

use App\Core\Domain\Concerns\HasInvitationLifecycle;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Shared\Traits\HasUuid;
use Database\Modules\Organization\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An offer of membership in an organization (ADR-031, §2.3).
 *
 * Organization is the FIRST consumer of the platform invitation architecture:
 * the lifecycle (pending → accepted/rejected/expired/cancelled), the expiry and
 * the acceptability check come from Core's `HasInvitationLifecycle`; this model
 * only adds the target (the organization) and the role to be granted.
 *
 * ONLY THE HASH IS STORED. `token_hash` is the sole persisted form of the token;
 * the raw token is emailed once and never saved or returned (ADR-025/031).
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $email
 * @property OrganizationRole $role
 * @property string $token_hash
 * @property \App\Shared\Enums\InvitationStatus $status
 * @property int|null $invited_by
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property int|null $accepted_by
 *
 * @see docs/modules/Organization.md §6
 */
final class OrganizationInvitation extends Model
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    use HasInvitationLifecycle;
    use HasUuid;

    protected $table = 'organization_invitations';

    protected $fillable = [
        'organization_id',
        'email',
        'role',
        'token_hash',
        'status',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_by',
    ];

    /**
     * Never expose the token hash — it is a credential derivative.
     *
     * @var array<int, string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePendingFor(Builder $query, int $organizationId, string $email): Builder
    {
        return $query
            ->where('organization_id', $organizationId)
            ->where('email', mb_strtolower($email))
            ->where('status', \App\Shared\Enums\InvitationStatus::Pending->value);
    }

    /**
     * `role` is cast here; the lifecycle casts (status, expires_at, accepted_at)
     * are merged by HasInvitationLifecycle.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
        ];
    }
}
