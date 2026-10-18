<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class DataTableService
{
    /** @var int[] */
    private const ALLOWED_PAGE_SIZES = [25, 50, 100];

    private const DEFAULT_PAGE_SIZE = 50;

    private const DEFAULT_SORT_DIR = 'asc';

    /**
     * Build a paginated, sorted, searchable table response array
     * conforming to the frontend TableResponse<T> contract.
     *
     * @param  Builder|HasMany  $query  Base query (with joins/selects already applied).
     * @param  Request  $request  The current HTTP request (reads sort, dir, page, pageSize, search).
     * @param  string[]  $sortable  Whitelist of allowed sort keys.
     * @param  array<string, string>  $sortColumnMap  Maps sort key → actual DB column (e.g. ['name' => 'default_cards.name']).
     * @param  string  $defaultSort  Default sort key.
     * @param  (callable(Builder, string): void)|null  $searchCallback  Optional callback to apply search filtering.
     * @param  callable(mixed): array  $rowMapper  Transforms each Eloquent model into a plain array for the frontend.
     * @param  array<string, string>  $filters  Filters the caller has already applied to `$query`, echoed back so the UI shows what the rows were narrowed by.
     * @return array{rows: array, total: int, page: int, pageSize: int, sort: array{key: string, direction: string}, search: string|null, filters: array<string, string>|null}
     */
    public static function buildResponse(
        Builder|HasMany $query,
        Request $request,
        array $sortable,
        array $sortColumnMap,
        string $defaultSort,
        ?callable $searchCallback,
        callable $rowMapper,
        string $defaultDirection = self::DEFAULT_SORT_DIR,
        array $filters = [],
    ): array {
        $sortKey = $request->input('sort', $defaultSort);
        $sortDir = $request->input('dir', $defaultDirection);

        if (! in_array($sortKey, $sortable)) {
            $sortKey = $defaultSort;
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = self::DEFAULT_SORT_DIR;
        }

        $pageSize = (int) $request->input('pageSize', self::DEFAULT_PAGE_SIZE);
        if (! in_array($pageSize, self::ALLOWED_PAGE_SIZES)) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        $search = $request->input('search');

        if ($search && $searchCallback) {
            $searchCallback($query, $search);
        }

        $sortColumn = $sortColumnMap[$sortKey] ?? $sortKey;

        $paginator = $query
            ->orderBy($sortColumn, $sortDir)
            ->paginate($pageSize);

        return [
            'rows' => $paginator->map($rowMapper)->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'pageSize' => $pageSize,
            'sort' => ['key' => $sortKey, 'direction' => $sortDir],
            'search' => $search,
            'filters' => $filters === [] ? null : $filters,
        ];
    }
}
