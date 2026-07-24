<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\PermissionRegistry;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reconciles the permission table with PermissionRegistry.
 *
 * Run on every deploy. Idempotent and additive: it creates what is missing and
 * reports what is orphaned, but never deletes — dropping a permission that a
 * role still holds is a decision a human makes, with `--prune`.
 */
final class SyncPermissionsCommand extends Command
{
    protected $signature = 'marketplace:sync-permissions
                            {--prune : Delete permissions no longer declared in the registry}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Create any permissions declared in PermissionRegistry that do not yet exist';

    public function handle(): int
    {
        $declared = PermissionRegistry::all();

        if ($this->option('dry-run')) {
            foreach ($declared as $guard => $names) {
                $this->line("<fg=cyan>{$guard}</>: ".count($names).' declared');
            }

            $this->newLine();
            $this->warn('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $created = PermissionRegistry::sync();

        if ($created === []) {
            $this->info('All declared permissions already exist.');
        } else {
            foreach ($created as $guard => $names) {
                $this->info("Created ".count($names)." permission(s) on the '{$guard}' guard:");
                $this->line('  '.implode(', ', $names));
            }
        }

        $orphans = PermissionRegistry::orphans();

        if ($orphans->isNotEmpty()) {
            $this->newLine();
            $this->warn("{$orphans->count()} permission(s) exist but are no longer declared:");

            foreach ($orphans as $orphan) {
                $this->line("  {$orphan->guard_name}: {$orphan->name}");
            }

            if ($this->option('prune')) {
                if (! $this->confirm('Delete these permissions? Any role holding them loses that access.', false)) {
                    $this->line('Skipped.');
                } else {
                    $orphans->each->delete();
                    $this->info('Pruned.');
                }
            } else {
                $this->line('Re-run with --prune to remove them.');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return self::SUCCESS;
    }
}
