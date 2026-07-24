<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Identity\Domain\DTOs\AdminUpdateUserDTO;
use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Shared\Enums\Status;

/**
 * An administrator's edit to another user's account.
 *
 * FORENSICALLY RECORDED (Phase 8 ruling). The write happens inside
 * `AuditContext::withReasonFor()`, so the Auditable trait on User captures the
 * before/after AND the admin's reason in one immutable entry — same mechanism
 * as a self-service edit, with the reason self-service does not supply. The
 * actor, IP, user-agent and correlation id are already on the context.
 *
 * ONE write, so ONE audit entry: name, phone, locale and status are collected
 * into a single update rather than applied field by field.
 *
 * PATCH semantics from the DTO's `present` list — @see UpdateProfileAction.
 */
final class AdminUpdateUserAction extends BaseAction
{
    public function __construct(
        private readonly LanguageRepositoryContract $languages,
        private readonly CountryRepositoryContract $countries,
        private readonly CurrencyRepositoryContract $currencies,
        private readonly TimezoneRepositoryContract $timezones,
    ) {}

    public function handle(mixed ...$arguments): User
    {
        /** @var User $user */
        $user = $arguments[0];
        /** @var AdminUpdateUserDTO $data */
        $data = $arguments[1];

        // Resolve the CONCRETE subclass (Customer, Seller), whatever type of
        // instance the caller passed — a route-bound base User from the API, or
        // a Filament record. The audit entry must name the same morph as the
        // user's self-service edits, and this is the one place that guarantees
        // it, so no caller has to remember.
        /** @var class-string<User> $model */
        $model = $user->type->model();
        $user = $model::query()->findOrFail($user->getKey());

        $changes = $this->buildChanges($data);

        if ($changes !== []) {
            // The reason rides the audit context for the duration of the write,
            // so the Auditable trait records WHY without the model layer ever
            // knowing about it. The UserObserver dispatches UserUpdated too.
            AuditContext::withReasonFor($data->reason, function () use ($user, $changes): void {
                $user->update($changes);
            });
        }

        return $user->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChanges(AdminUpdateUserDTO $data): array
    {
        $changes = [];

        if ($data->has('first_name')) {
            $changes['first_name'] = $data->firstName;
        }

        if ($data->has('last_name')) {
            $changes['last_name'] = $data->lastName;
        }

        if ($data->has('phone')) {
            $changes['phone'] = $data->phone;
        }

        // Suspend or reactivate. Validation already limited it to a real case.
        if ($data->has('status') && $data->status !== null) {
            $changes['status'] = Status::from($data->status);
        }

        if ($data->has('language_code')) {
            $changes['language_id'] = $data->languageCode === null
                ? null
                : $this->languages->findByCode($data->languageCode)?->getKey();
        }

        if ($data->has('country_code')) {
            $changes['country_id'] = $data->countryCode === null
                ? null
                : $this->countries->findByIso2($data->countryCode)?->getKey();
        }

        if ($data->has('currency_code')) {
            $changes['currency_id'] = $data->currencyCode === null
                ? null
                : $this->currencies->findByCode($data->currencyCode)?->getKey();
        }

        if ($data->has('timezone_name')) {
            $changes['timezone_id'] = $data->timezoneName === null
                ? null
                : $this->timezones->findByName($data->timezoneName)?->getKey();
        }

        return $changes;
    }
}
