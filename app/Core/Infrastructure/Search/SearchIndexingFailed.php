<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Search;

use App\Core\Domain\Exceptions\BaseException;

/**
 * Raised when an OpenSearch bulk request reports per-document failures.
 *
 * Unlike most domain exceptions this one IS reportable: a document that fails
 * to index is silent data loss from the customer's point of view — the product
 * exists but cannot be found — and nothing else will surface it.
 */
final class SearchIndexingFailed extends BaseException
{
    protected int $status = 500;

    protected bool $reportable = true;

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromBulkResponse(string $index, array $response): self
    {
        $failures = [];

        foreach ($response['items'] ?? [] as $item) {
            $result = $item['index'] ?? $item['create'] ?? $item['update'] ?? [];

            if (isset($result['error'])) {
                $failures[] = [
                    'id' => $result['_id'] ?? null,
                    'type' => $result['error']['type'] ?? null,
                    'reason' => $result['error']['reason'] ?? null,
                ];
            }
        }

        return (new self(sprintf(
            'Bulk indexing into "%s" failed for %d document(s).',
            $index,
            count($failures),
        )))->withContext([
            'index' => $index,
            // Cap the payload: a failed 500-document bulk should not write a
            // 500-entry array into every log aggregator downstream.
            'failures' => array_slice($failures, 0, 10),
            'failure_count' => count($failures),
        ]);
    }
}
