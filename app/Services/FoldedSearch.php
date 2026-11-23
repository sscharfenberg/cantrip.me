<?php

namespace App\Services;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Comparisons for text a user typed, ignoring case (and accents, where the
 * method says so): `dandan` finds Dandân, `HYDRA` finds Hydra, `number:s3`
 * finds collector number S3.
 *
 * Postgres compares text exactly, so plain `=` or `LIKE` would make every one
 * of those come back empty. Accents are folded with `unaccent` — an extension,
 * created by the migration that makes user names case-insensitive — which
 * exists on Postgres only; elsewhere (the SQLite test database) the methods
 * fold case but not accents.
 *
 * Wildcards in a `whereContains` term are left alone, as the searches always
 * did: `_` matches any one character.
 */
final class FoldedSearch
{
    /**
     * Constrain `$column` to equal `$value`, ignoring case — for a value typed
     * by hand that is stored in a fixed case, like a collector number (`S3`,
     * `WAR-158`). `lower()` on both sides works on both drivers; SQLite's folds
     * ASCII only, which is all a collector number has.
     */
    public static function whereEqualsIgnoringCase(Builder $query, string $column, string $value): Builder
    {
        return $query->whereRaw('lower('.$query->getGrammar()->wrap($column).') = lower(?)', [$value]);
    }

    /**
     * Constrain `$column` to equal one of `$values`, ignoring case and (on
     * Postgres) accents — a pre-filter for names typed by hand, where PHP then
     * decides which row really matches.
     *
     * @param  list<string>  $values
     */
    public static function whereInIgnoringCaseAndAccents(Builder $query, string $column, array $values): Builder
    {
        if ($values === []) {
            return $query->whereRaw('1 = 0');
        }

        $folded = $query->getConnection()->getDriverName() === 'pgsql'
            ? ['lower(unaccent('.$query->getGrammar()->wrap($column).'::text))', 'lower(unaccent(?))']
            : ['lower('.$query->getGrammar()->wrap($column).')', 'lower(?)'];

        return $query->whereRaw(
            $folded[0].' IN ('.implode(', ', array_fill(0, count($values), $folded[1])).')',
            array_values($values),
        );
    }

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
