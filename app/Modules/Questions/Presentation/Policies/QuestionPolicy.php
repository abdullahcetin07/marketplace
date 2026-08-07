<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Policies;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Questions\Domain\Models\Question;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Who answers, who hides, and who may take a question back (ADR-070/071).
 *
 * **THE TWO LEVERS BELONG TO DIFFERENT PEOPLE AND NEITHER CAN REACH THE OTHER'S.**
 * A seller answers and cannot hide — the party a question is aimed at does not get
 * to make it disappear. An admin hides and cannot answer — the platform speaking
 * in a merchant's place is a promise the merchant did not make. Both refusals are
 * written here as explicit type checks rather than left to an unheld permission,
 * so granting the wrong ability by accident still fails.
 *
 * **`answer` IS TWO CONDITIONS, NOT ONE.** Holding `question.answer` is the
 * permission half; the question's `store_uuid` being one of the actor's own live
 * stores is the tenancy half (ADR-030 — there is no Filament tenant, so scope is
 * per-resource and per-policy). The seller panel's query already filters to the
 * same set; this is the side that cannot be got wrong by a mis-written scope.
 *
 * **`delete` OVERRIDES THE BASE CLASS**, exactly as `ReviewPolicy`'s does and for
 * the same reason: `BasePolicy::allowIf()` checks a permission BEFORE ownership,
 * and the only actor who may delete a question is the CUSTOMER who asked it —
 * customers hold no `question.*` permission at all. Leaving the default would deny
 * the asker and, worse, allow anybody an operator later granted `question.delete`.
 *
 * @extends BasePolicy<Question>
 *
 * @see docs/modules/Questions.md §6, §7, §8
 */
final class QuestionPolicy extends BasePolicy
{
    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
        private readonly StoreQueryContract $stores,
    ) {}

    /**
     * Answering — the target seller's, and only for their own stores (ADR-071).
     *
     * @param Question $question
     */
    public function answer(User $user, Model $question): Response
    {
        if ($user->type !== UserType::Seller) {
            /*
            | NOT AN ADMIN'S, EXPLICITLY. This is the module's central asymmetry
            | and the one somebody would otherwise "fix" by granting an admin the
            | ability: the platform must not answer in a merchant's place, so an
            | admin who somehow held `question.answer` is still refused here.
            */
            return Response::deny(__('errors.forbidden'));
        }

        if (! $user->can('question.answer')) {
            return Response::deny(__('errors.missing_permission', ['permission' => 'question.answer']));
        }

        return in_array($question->store_uuid, $this->sellerStoreUuids($user), true)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * Hiding and un-hiding — the admin's only lever (ADR-071).
     */
    public function moderate(User $user): Response
    {
        if ($user->type !== UserType::Admin) {
            // Sellers explicitly. A merchant able to hide criticism aimed at
            // them makes every surviving answer worthless.
            return Response::deny(__('errors.forbidden'));
        }

        return $user->can('question.moderate')
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => 'question.moderate']));
    }

    /**
     * Taking one back — the asker's, and only theirs.
     *
     * @param Question $model
     */
    public function delete(User $user, Model $model): Response
    {
        if ($user->type !== UserType::Customer) {
            /*
            | NOT A SELLER'S AND NOT AN ADMIN'S. A merchant deleting a question
            | aimed at them is the suppression `moderate` exists to keep out of
            | their hands; an admin who needs one gone hides it, which is
            | reversible and leaves a reason.
            */
            return Response::deny(__('errors.forbidden'));
        }

        return $this->owns($user, $model)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    protected function permissionPrefix(): string
    {
        return 'question';
    }

    /**
     * The asker, by internal id — the column every scoped query filters on and
     * the value compared against the authenticated actor.
     *
     * @param Question $model
     */
    protected function owns(User $user, Model $model): bool
    {
        return $model->customer_id === (int) $user->getKey();
    }

    /**
     * Every live store uuid this seller's organisations own.
     *
     * THE SAME TWO-HOP THE SELLER PANELS USE (ADR-030/040): the Core
     * authorization contract answers memberships in internal IDS, the store
     * contract turns each organisation into its live stores' uuids, and the
     * question carries the uuid. Questions imports neither module.
     *
     * AN EMPTY SET DENIES EVERYTHING, which is the correct failure direction: a
     * seller who belongs to nothing answers nothing.
     *
     * @return array<int, string>
     */
    private function sellerStoreUuids(User $user): array
    {
        $uuids = [];

        foreach ($this->authz->organizationIdsForUser((int) $user->getKey()) as $organizationId) {
            foreach (array_keys($this->stores->liveStoresForOrganization($organizationId)) as $storeUuid) {
                $uuids[] = (string) $storeUuid;
            }
        }

        return $uuids;
    }
}
