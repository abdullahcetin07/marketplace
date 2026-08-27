<?php

declare(strict_types=1);

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

/*
|--------------------------------------------------------------------------
| What the settings in config/catalog.php actually do to a query (ADR-090)
|--------------------------------------------------------------------------
|
| THE REST OF THE SUITE CANNOT SEE THIS CLASS OF BUG. Typo tolerance, synonyms
| and ranking are not code paths — they are numbers and word lists we hand to an
| engine, and the only way to know `seurm` finds `serum` is to ask a running
| Meilisearch. Everything else about the feature is provable with a stub (see
| `SearchEngineListingTest`); this file is here for the part that is not.
|
| It runs against a SCRATCH INDEX it creates and deletes, never the application's
| own, and skips when there is no engine — a suite that fails on a developer's
| laptop for want of infrastructure is a suite people learn to ignore.
|
*/

function meiliClient(): ?Client
{
    /*
    | The key comes from the ENVIRONMENT, never from `.env` — the suite does not
    | load it, and phpunit.xml is committed, so a secret cannot live in either.
    | Same shape as the pgsql integration file's `PGSQL_TEST_*`:
    |
    |     MEILI_TEST_KEY=$(grep MEILI_MASTER_KEY /etc/meilisearch.env | cut -d= -f2) \
    |         php vendor/bin/pest tests/Integration/MeilisearchSettingsTest.php
    */
    $host = (string) (getenv('MEILI_TEST_HOST') ?: config('scout.meilisearch.host', 'http://127.0.0.1:7700'));
    $key = (string) (getenv('MEILI_TEST_KEY') ?: config('scout.meilisearch.key', ''));

    try {
        $client = new Client($host, $key === '' ? null : $key);

        // An AUTHENTICATED call: `/health` answers without a key, so probing it
        // would report a reachable engine this test cannot actually use.
        $client->getIndexes();

        return $client;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Meilisearch applies settings and documents asynchronously; a test that asks
 * immediately is testing the previous state.
 *
 * @param array<string, mixed>|object $task
 */
function awaitTask(Client $client, array|object $task): void
{
    $uid = is_array($task) ? ($task['taskUid'] ?? $task['uid'] ?? null) : ($task->getTaskUid() ?? null);

    if ($uid !== null) {
        $client->waitForTask((int) $uid, 10_000);
    }
}

beforeEach(function (): void {
    $client = meiliClient();

    if ($client === null) {
        $this->markTestSkipped('Meilisearch is not reachable.');
    }

    $this->client = $client;
    $this->indexName = 'ci_search_settings';

    try {
        awaitTask($client, $client->deleteIndex($this->indexName));
    } catch (ApiException) {
        // Nothing to clean up on the first run.
    }

    /** @var array<string, mixed> $search */
    $search = (array) config('catalog.search', []);

    $index = $client->index($this->indexName);

    awaitTask($client, $client->createIndex($this->indexName, ['primaryKey' => 'uuid']));

    // THE SAME SETTINGS THE COMMAND PUSHES, read from the same config — the
    // point is to test what production will run, not a hand-written copy.
    awaitTask($client, $index->updateSettings([
        'searchableAttributes' => $search['searchable_attributes'] ?? [],
        'filterableAttributes' => $search['filterable_attributes'] ?? [],
        'sortableAttributes' => $search['sortable_attributes'] ?? [],
        'rankingRules' => $search['ranking_rules'] ?? [],
        'typoTolerance' => $search['typo_tolerance'] ?? [],
        'synonyms' => $search['synonyms'] ?? [],
    ]));

    awaitTask($client, $index->addDocuments([
        ['uuid' => 'a', 'title' => 'Vitamin C Serum', 'brand' => 'Uriage', 'category' => 'Serumlar', 'description' => 'Cilt bakım serumu', 'status' => 'published', 'is_sellable' => true],
        ['uuid' => 'b', 'title' => 'Güneş Kremi SPF 50', 'brand' => 'Avène', 'category' => 'Güneş Bakım', 'description' => 'Yüksek koruma', 'status' => 'published', 'is_sellable' => true],
        ['uuid' => 'c', 'title' => 'Depiderm Leke Karşıtı', 'brand' => 'Uriage', 'category' => 'Bakım', 'description' => 'Leke bakımı', 'status' => 'published', 'is_sellable' => false],
    ]));
});

afterEach(function (): void {
    if (isset($this->client)) {
        try {
            $this->client->deleteIndex($this->indexName);
        } catch (Throwable) {
            // A leftover scratch index is cleaned by the next run's beforeEach.
        }
    }
});

/** @return array<int, string> */
function hits(object $index, string $query): array
{
    return array_column($index->search($query)->getHits(), 'uuid');
}

it('forgives the typos the fold could not', function (): void {
    $index = $this->client->index($this->indexName);

    // The exact queries from the work order, and the whole reason for tier 2.
    expect(hits($index, 'seurm'))->toContain('a')
        ->and(hits($index, 'depidem'))->toContain('c');
});

it('leaves short words exact', function (): void {
    /*
    | Typo tolerance starts at five characters on purpose: at three or four
    | almost every word is one edit from another, and "krem" would answer for
    | "krom" as confidently as for itself.
    */
    $index = $this->client->index($this->indexName);

    expect(hits($index, 'krom'))->toBe([]);
});

it('applies the synonyms from config', function (): void {
    /*
    | `uriaj` is not a diacritic variant of `uriage` — it is how a Turkish
    | speaker spells it, which no folding can reach. That is what the synonym
    | list is for, and it is version-controlled rather than typed into a panel.
    */
    $index = $this->client->index($this->indexName);

    expect(hits($index, 'uriaj'))->toContain('a')
        ->and(hits($index, 'spf'))->toContain('b');
});

it('keeps the tier-1 wins that people already rely on', function (): void {
    // Diacritics were fixed before the engine existed and must not regress
    // when the engine takes over the query path.
    $index = $this->client->index($this->indexName);

    expect(hits($index, 'gunes'))->toContain('b')
        ->and(hits($index, 'avene'))->toContain('b');
});

it('lifts what somebody can actually buy', function (): void {
    /*
    | The one custom ranking rule (`is_sellable:desc`). Both documents match
    | "uriage"; only one of them can be bought today.
    */
    $index = $this->client->index($this->indexName);

    expect(hits($index, 'uriage'))->toBe(['a', 'c']);
});
