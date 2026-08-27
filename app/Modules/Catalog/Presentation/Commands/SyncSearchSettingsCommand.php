<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Console\Command;
use Meilisearch\Client;
use Throwable;

/**
 * Push the index settings from `config/catalog.php` to Meilisearch (ADR-090).
 *
 * **SETTINGS ARE CODE, AND THIS IS HOW THEY GET THERE.** Searchable fields,
 * facets, ranking, typo thresholds and the synonym list all live in a
 * version-controlled config; an index configured by hand through the engine's
 * API is a production difference nobody can review or restore.
 *
 * **IDEMPOTENT ON PURPOSE** — running it twice changes nothing, so it belongs in
 * a deploy step rather than in somebody's memory. Meilisearch applies settings
 * asynchronously; this enqueues the tasks and reports the ids rather than
 * pretending they are already live.
 *
 * Does nothing, loudly, when Scout has no engine: the rollout ships the code
 * first and turns the engine on afterwards, and a command that exploded in that
 * window would make the safe order look like the broken one.
 */
final class SyncSearchSettingsCommand extends Command
{
    protected $signature = 'search:sync-settings';

    protected $description = 'Push searchable fields, facets, ranking, typo tolerance and synonyms to Meilisearch';

    public function handle(): int
    {
        if ((string) config('scout.driver') !== 'meilisearch') {
            $this->warn('SCOUT_DRIVER is not meilisearch — nothing to sync.');

            return self::SUCCESS;
        }

        /** @var array<string, mixed> $search */
        $search = (array) config('catalog.search', []);

        $index = (string) (new Product)->searchableAs();

        try {
            /*
            | The client is built from config rather than pulled off Scout's
            | engine: `EngineManager::engine()` is typed as the abstract Engine,
            | which has no `getClient()`, and reaching through it would be an
            | unchecked cast to make a static analyser quiet about a fact it is
            | right to doubt.
            */
            $client = new Client(
                (string) config('scout.meilisearch.host', 'http://127.0.0.1:7700'),
                (string) config('scout.meilisearch.key', ''),
            );

            $tasks = $client->index($index)->updateSettings([
                'searchableAttributes' => $search['searchable_attributes'] ?? [],
                'filterableAttributes' => $search['filterable_attributes'] ?? [],
                'sortableAttributes' => $search['sortable_attributes'] ?? [],
                'rankingRules' => $search['ranking_rules'] ?? [],
                'typoTolerance' => $search['typo_tolerance'] ?? [],
                'synonyms' => $search['synonyms'] ?? [],
            ]);
        } catch (Throwable $e) {
            $this->error('Meilisearch refused the settings: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Settings queued on index "%s" (task %s). %d synonym groups, typo tolerance %s.',
            $index,
            (string) ($tasks['taskUid'] ?? $tasks['uid'] ?? '?'),
            count((array) ($search['synonyms'] ?? [])),
            ($search['typo_tolerance']['enabled'] ?? false) ? 'on' : 'off',
        ));

        return self::SUCCESS;
    }
}
