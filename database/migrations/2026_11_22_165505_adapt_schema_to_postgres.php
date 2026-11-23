<?php

use App\Models\CardStack;
use App\Models\User;
use App\Services\FoldedSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What the schema needs on Postgres that the column definitions alone do
 * not say. Other drivers keep their own rules, so this does nothing there.
 *
 * Case-insensitive user names and emails. Postgres compares text exactly,
 * but both columns are unique, so "Sven" and "sven" must not be two
 * accounts, and login looks the user up by name (`config/fortify.php`), so
 * typing it in another case must still find them. `citext` gives both
 * columns that behaviour for `=`, unique indexes and `unique:` validation
 * alike, with no change to the code that queries them.
 *
 * `unaccent`, which {@see FoldedSearch} needs. Both extensions are trusted,
 * so the database's owner can create them without superuser rights.
 *
 * `card_stacks.amount` as `integer`. Postgres has no unsigned types, so the
 * `unsignedSmallInteger` the table was created with is a `smallint` there,
 * which stops at 32 767 — below {@see CardStack::MAX_AMOUNT}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('ALTER TABLE users ALTER COLUMN name TYPE citext, ALTER COLUMN email TYPE citext');
        DB::statement('ALTER TABLE card_stacks ALTER COLUMN amount TYPE integer');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE card_stacks ALTER COLUMN amount TYPE smallint');
        DB::statement('ALTER TABLE users ALTER COLUMN name TYPE varchar('.User::NAME_MAX.'), ALTER COLUMN email TYPE varchar(255)');
    }
};
