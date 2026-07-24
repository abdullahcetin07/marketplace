<?php

declare(strict_types=1);

namespace App\Core\Application\Actions;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A single, named, atomic use case.
 *
 * ACTION vs SERVICE — the distinction this codebase enforces:
 *
 *   BaseAction  does exactly one thing and owns its transaction.
 *               ApproveStoreAction, RecalculateBuyBoxAction.
 *               Stateless, one public entry point, trivially unit-testable,
 *               trivially queueable.
 *
 *   BaseService orchestrates several actions and repositories behind a
 *               cohesive API for one aggregate. It does not open transactions
 *               of its own beyond what the actions it calls already do.
 *
 * If you cannot name an action with a single verb and a single noun, it is
 * doing too much and should be a service that calls several actions.
 *
 * Subclasses implement handle(); callers use run() (or the static make()->run()
 * shorthand) so the transaction and hook behaviour is never bypassed.
 *
 *     final class ApproveStoreAction extends BaseAction
 *     {
 *         public function handle(Store $store, Admin $approver): Store { ... }
 *     }
 *
 *     ApproveStoreAction::make()->run($store, $admin);
 *
 * @see docs/001_Architecture.md §"Actions and services"
 */
abstract class BaseAction
{
    /**
     * Wrap handle() in a database transaction. Turn off for actions that only
     * read, or that talk to an external API and must not hold a transaction
     * open across the network call.
     */
    protected bool $useTransaction = true;

    /**
     * Deadlock retry attempts passed to DB::transaction().
     */
    protected int $transactionAttempts = 1;

    /**
     * Resolve through the container so constructor dependencies are injected.
     */
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Execute the action with its transaction and lifecycle hooks.
     */
    public function run(mixed ...$arguments): mixed
    {
        $this->before(...$arguments);

        try {
            $result = $this->useTransaction
                ? DB::transaction(fn (): mixed => $this->handle(...$arguments), $this->transactionAttempts)
                : $this->handle(...$arguments);
        } catch (Throwable $exception) {
            $this->onFailure($exception, ...$arguments);

            throw $exception;
        }

        $this->after($result, ...$arguments);

        return $result;
    }

    /**
     * The work itself. Signature is defined by the subclass.
     */
    abstract public function handle(mixed ...$arguments): mixed;

    /**
     * Runs before the transaction opens. Use for guard clauses that should
     * fail without touching the database.
     */
    protected function before(mixed ...$arguments): void
    {
        //
    }

    /**
     * Runs after the transaction commits — the only safe place to dispatch
     * side effects (emails, webhooks, search indexing) that must not fire if
     * the transaction rolls back.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        //
    }

    /**
     * Runs after rollback, before the exception is rethrown. For logging only:
     * do not swallow the exception here.
     */
    protected function onFailure(Throwable $exception, mixed ...$arguments): void
    {
        //
    }
}
