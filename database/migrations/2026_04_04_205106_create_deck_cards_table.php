<?php

use App\Enums\CardLanguage;
use App\Enums\DeckZone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deck_cards', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->uuid('id')->primary();
            $table->foreignUuid('deck_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUuid('oracle_card_id')
                ->constrained('oracle_cards')
                ->cascadeOnDelete();
            $table->foreignUuid('default_card_id')
                ->constrained('default_cards')
                ->cascadeOnDelete();
            $table->foreignUuid('category_id')
                ->nullable()
                ->constrained('deck_categories')
                ->nullOnDelete();
            $table->string('zone', 16)
                ->default(DeckZone::Main->value);
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->unsignedTinyInteger('finish')->default(1);
            $table->string('language', 3)->default(CardLanguage::En->value);
            $table->timestamps();

            $table->index(['deck_id', 'zone']);
            $table->index(['oracle_card_id']);
            $table->index(['default_card_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deck_cards');
    }
};
