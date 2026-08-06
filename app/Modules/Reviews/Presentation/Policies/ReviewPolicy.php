<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Reviews\Domain\Models\Review;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may read the queue, who may decide, and who may take a review back
 * (ADR-068).
 *
 * **THE SELLER APPEARS NOWHERE, AND THAT IS THE MODULE'S CENTRAL RULE.** Not to
 * approve, not to reject, not to hide, not even to view the queue. The party a
 * review judges gets no lever over it, and the strongest form of that is an
 * ability that does not exist rather than a branch that denies them.
 *
 * **`delete` IS OVERRIDDEN BECAUSE THE BASE CLASS ASKS FOR A PERMISSION FIRST.**
 * `BasePolicy::allowIf()` checks `review.delete` before it ever looks at
 * ownership — correct for admin-scoped resources, and exactly wrong here: the one
 * actor who may delete a review is the CUSTOMER who wrote it, and customers hold
 * no `review.*` permissions at all. Leaving the default would have denied the
 * owner and, worse, allowed anybody an operator later granted the permission to.
 * So the check is ownership alone, and no permission opens this door.
 *
 * @extends BasePolicy<Review>
 *
 * @see docs/modules/Reviews.md §6, §8
 */
final class ReviewPolicy extends BasePolicy
{
    /**
     * Approving or rejecting — the moderation lever (ADR-068).
     *
     * A SEPARATE ABILITY FROM `update`, because a review is never updated: the
     * text is the buyer's and nobody edits it. What a moderator changes is its
     * VISIBILITY, and giving that its own name keeps "may decide" from ever
     * being confused with "may rewrite".
     *
     * Admin and Editor hold it (`RolePermissionSeeder`); Super Admin bypasses
     * every policy in `before()`.
     */
    public function moderate(User $user): Response
    {
        if ($user->type !== UserType::Admin) {
            // Sellers and customers, explicitly. A merchant reaching this method
            // meets a documented refusal rather than a missing one.
            return Response::deny(__('errors.forbidden'));
        }

        return $user->can('review.moderate')
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => 'review.moderate']));
    }

    /**
     * Taking one back — the author's, and only theirs.
     *
     * @param Review $model
     */
    public function delete(User $user, Model $model): Response
    {
        if ($user->type !== UserType::Customer) {
            /*
            | NOT AN ADMIN'S EITHER, in v1. An admin who wants a published review
            | gone has `review.moderate`… and rejecting is only open while it is
            | pending, so the honest statement is that removing a LIVE review is
            | not a v1 operation for anybody but its author. Recorded rather than
            | quietly granted: the alternative is an admin delete nobody
            | specified, on content somebody else wrote.
            */
            return Response::deny(__('errors.forbidden'));
        }

        return $this->owns($user, $model)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    protected function permissionPrefix(): string
    {
        return 'review';
    }

    /**
     * The author, by internal id.
     *
     * BY ID RATHER THAN UUID because that is the column every scoped query
     * filters on and the value compared against the authenticated actor —
     * authorising on a different fact than the query returned is how the two
     * drift apart.
     *
     * @param Review $model
     */
    protected function owns(User $user, Model $model): bool
    {
        return $model->customer_id === (int) $user->getKey();
    }
}
