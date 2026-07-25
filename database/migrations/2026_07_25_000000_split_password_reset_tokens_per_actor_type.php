<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One password-reset token table per actor type.
 *
 * SECURITY FIX. The three brokers in config/auth.php were separate in name
 * only: all of them read and wrote `password_reset_tokens`, which is keyed by
 * email alone. Because `users` is unique on (type, email), the same address may
 * legitimately belong to both a customer and an admin — and a token issued by
 * the customers broker was then found, and accepted, by the admins broker. A
 * customer reset link opened the admin account.
 *
 * Keying the table by email is Laravel's design and it is fine for one actor
 * type; the mistake was pointing three brokers at one such table. Each broker
 * now owns its own, so a token issued for one type simply does not exist for
 * another. Customers keep `password_reset_tokens` — the majority of rows and
 * the framework default — so nothing has to be migrated.
 *
 * Pending tokens are not copied across: an outstanding reset link for an admin
 * or seller stops working, which is the correct outcome for a link that might
 * have been redeemable against the wrong account.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'admin_password_reset_tokens',
        'seller_password_reset_tokens',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestampTz('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::dropIfExists($name);
        }
    }
};
