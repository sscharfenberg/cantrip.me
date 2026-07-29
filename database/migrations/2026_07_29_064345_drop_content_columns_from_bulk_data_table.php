<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scryfall moved its bulk downloads to gzipped JSON Lines and stopped
 * publishing `content_type` / `content_encoding` in the /bulk-data catalog
 * (alongside renaming `size` → `compressed_size` and `download_uri` →
 * `jsonl_download_uri`). Both columns are NOT NULL with no default, so the
 * import cannot insert a row while they still exist — and nothing reads them.
 *
 * A new migration rather than an edit to `create_bulk_data_table` because
 * prod carries real user data and cannot be rebuilt from scratch.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bulk_data', function (Blueprint $table) {
            $table->dropColumn(['content_type', 'content_encoding']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restored with empty-string defaults, unlike the NOT NULL originals —
     * the values are no longer obtainable from Scryfall, so a rollback onto
     * a populated table needs something to backfill with.
     */
    public function down(): void
    {
        Schema::table('bulk_data', function (Blueprint $table) {
            $table->string('content_type', 32)->default('');
            $table->string('content_encoding', 16)->default('');
        });
    }
};
