<?php

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
        Schema::create('default_card_relations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->foreignUuid('source_default_card_id')
                ->references('id')
                ->on('default_cards')
                ->cascadeOnDelete();
            $table->foreignUuid('related_default_card_id')
                ->references('id')
                ->on('default_cards')
                ->cascadeOnDelete();
            $table->string('component', 16);

            $table->primary(['source_default_card_id', 'related_default_card_id', 'component']);
            $table->index(['source_default_card_id', 'component']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('default_card_relations');
    }
};
