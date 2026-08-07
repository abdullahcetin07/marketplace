<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shopper's question, and the seller's answer to it (ADR-070/071).
 *
 * **THE ANSWER LIVES ON THE SAME ROW**, and that is a decision rather than a
 * shortcut. v1 is one question and one answer — no thread, no follow-ups (§11) —
 * so a separate `answers` table would be a join for a column, plus a second place
 * for "is this answered?" to disagree with the status. If a thread ever ships, it
 * arrives as its own table and this column becomes the first entry in it.
 *
 * **`store_uuid` IS THE TARGET AND IT IS A SNAPSHOT** (ADR-070). Copied from the
 * featured offer at ask time, so a later buy-box change never re-aims a past
 * question — and it doubles as the seller panel's tenancy key, which is why it is
 * indexed with the status.
 *
 * **THE HIDE IS THREE NULLABLE COLUMNS, NOT A STATUS**, because an admin may take
 * down a PENDING question as well as an answered one and may reverse either.
 * Public = `status = answered` AND `hidden_at IS NULL`, computed on read with
 * nothing denormalised to drift.
 *
 * NOT ONE FOREIGN KEY LEAVES THIS MODULE — the product, the store and the selling
 * org are bare uuids. Questions imports no module (ADR-002), and a database-level
 * FK would be the same coupling wearing a different hat.
 *
 * NO MONEY AND NO MEDIA. There is no rating and no price, so the minor-units rule
 * does not apply here at all, and a question is text (§11).
 *
 * @see App\Modules\Questions\Domain\Models\Question
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->uuid('product_uuid');

            /*
            | The asker, as the ADR-040 id/uuid pair. The id scopes "sorularım"
            | and is compared against the authenticated actor; neither ever
            | reaches a public payload, because a question is signed with a
            | masked name and nothing else.
            */
            $table->unsignedBigInteger('customer_id');
            $table->uuid('customer_uuid');
            $table->string('asker_name');

            // THE TARGET (ADR-070) — snapshotted, never sent by the client.
            $table->uuid('store_uuid');
            $table->uuid('selling_org_uuid');

            $table->text('body');
            $table->string('status', 20)->default('pending');

            // The seller's side. `answered_by` is a seller USER id — a Seller
            // Employee may answer (§6) — and never leaves the application: a
            // shopper sees the shop's answer, not an individual's.
            $table->text('answer_body')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->unsignedBigInteger('answered_by')->nullable();

            // The admin's only lever (ADR-071), and it is reversible.
            $table->timestampTz('hidden_at')->nullable();
            $table->unsignedBigInteger('hidden_by')->nullable();
            $table->text('hidden_reason')->nullable();

            $table->timestampsTz();

            // The public product-page list, filtered to answered.
            $table->index(['product_uuid', 'status']);
            // The seller panel: their store's questions, pending first.
            $table->index(['store_uuid', 'status']);
            // "Sorularım".
            $table->index('customer_id');
            // The admin queue, which reads one status across every seller.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
