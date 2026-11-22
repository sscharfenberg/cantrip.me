<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A symbol's mana value is not always a whole number: Scryfall gives the
 * half-mana symbols `{½}`, `{HW}` and `{HR}` 0.5, and `{1000000}` a million.
 * An integer column made Postgres refuse the whole symbols import; MariaDB
 * had rounded the halves to 1. Same type as `oracle_cards.cmc`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('symbols', function (Blueprint $table) {
            $table->decimal('cmc', 8, 1)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('symbols', function (Blueprint $table) {
            $table->unsignedInteger('cmc')->nullable()->change();
        });
    }
};
