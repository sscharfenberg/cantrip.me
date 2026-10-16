<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a non-land card can search its controller's library for — Three
 * Visits, Rampant Growth, Cultivate, Sakura-Tribe Elder. Same grammar as
 * `fetch_pattern` (`basic`, `basic:<WUBRG>`, `typed:<WUBRG>`, `any`), so the
 * deck page resolves both against the deck's lands with one resolver, but a
 * separate column: `fetch_pattern` is the curated land-only `otag:fetchland`,
 * this one is `otag:tutor-land` narrowed to non-lands, and each oracle-tag
 * sync clears its own column before applying, so two syncs cannot share one.
 *
 * A new migration rather than an edit to `create_oracle_cards_table` because
 * prod carries real user data and cannot be rebuilt from scratch — the column
 * has to arrive by deploy. `ShadowTableService` builds its shadow tables with
 * `CREATE TABLE LIKE` off the live schema, so this migration must land BEFORE
 * the next `scryfall:update`, or `scryfall:oracle-tags --target=shadow` writes
 * a column the shadow table does not have.
 *
 * Between deploy and that sync the column is NULL for every row: land-search
 * spells then count as no source at all on the deck stats page, exactly as
 * before this column existed. Nothing errors. Run `php artisan
 * scryfall:oracle-tags` after deploying to fill it without waiting for the
 * nightly run.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('oracle_cards', function (Blueprint $table) {
            $table->string('land_search_pattern', 16)->nullable()->after('fetch_pattern');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oracle_cards', function (Blueprint $table) {
            $table->dropColumn('land_search_pattern');
        });
    }
};
