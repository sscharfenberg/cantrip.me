<?php

namespace App\Services;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * "Contains, ignoring case and accents" for text a user typed into a search
 * box: `dandan` finds Dandân, `HYDRA` finds Hydra.
 *
 * Postgres compares text exactly, so plain `LIKE` would make every one of
 * those searches come back empty. There it is `unaccent(column) ILIKE
 * unaccent(term)` — `unaccent` is an extension, created by the migration that
 * makes user names case-insensitive. Elsewhere (the SQLite test database) it
 * is Laravel's case-insensitive `whereLike`, which folds case but not accents.
 *
 * Wildcards in the term are left alone, as the searches always did: `_`
 * matches any one character.
 */
final class FoldedSearch
{
    /** Constrain `$column` to contain `$term`, ignoring case and accents. */
    public static function whereContains(Builder $query, string $column, string $term, string $boolean = 'and'): void
    {
        $pattern = "%{$term}%";

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            $query->whereRaw(
                'unaccent('.$query->getGrammar()->wrap($column).'::text) ILIKE unaccent(?)',
                [$pattern],
                $boolean,
            );

            return;
        }

        $query->whereLike($column, $pattern, false, $boolean);
    }

    /** {@see whereContains()} as an OR branch. */
    public static function orWhereContains(Builder $query, string $column, string $term): void
    {
        self::whereContains($query, $column, $term, 'or');
    }
}
