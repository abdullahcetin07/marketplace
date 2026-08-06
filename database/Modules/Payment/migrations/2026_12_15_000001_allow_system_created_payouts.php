<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payout the platform decided on its own (owner decision, 2026-08-06).
 *
 * S3 SHIPPED ELIGIBILITY AND LEFT THE DECISION TO AN ADMIN; the owner has since
 * chosen to automate the decision too — a nightly job creates one pending payout
 * per seller for everything that has become payable. So `created_by` stops being
 * mandatory: **NULL now means "nobody decided this, the schedule did".**
 *
 * NULL RATHER THAN A SYSTEM USER ROW, which is the alternative and the worse one.
 * A synthetic "system" account would be a real row in `users` that can be
 * authenticated against, granted roles and impersonated — an account nobody owns
 * with the authority to move money. An absent actor cannot be logged into.
 *
 * IT IS ALSO THE COLUMN THAT TELLS THE TWO KINDS APART. An operator reconciling a
 * batch needs to know which transfers a person chose and which the schedule
 * proposed, and that question has exactly one honest answer here rather than a
 * second boolean that could disagree with it.
 *
 * `settled_by` STAYS AS IT WAS — nullable, and populated by whoever marks the
 * transfer paid. Automating the decision does not automate the bank: a human still
 * makes the transfer and records that they did (ADR-062, the software moves no
 * money).
 *
 * @see App\Modules\Payment\Application\Jobs\CreateDuePayoutsJob
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
        | NOT REVERSIBLE IN PRACTICE, and saying so is more useful than a `change()`
        | that fails at 3 a.m.: by the time anyone rolls this back there are
        | automatic payouts with a null actor, and re-imposing NOT NULL would have
        | to invent an admin who decided them. Rolling back means deciding what
        | those rows mean first.
        */
        Schema::table('payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }
};
