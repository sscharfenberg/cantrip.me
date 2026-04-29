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
        Schema::create('deck_card_card_stack', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->foreignUuid('deck_card_id')
                ->constrained('deck_cards')
                ->cascadeOnDelete();
            $table->foreignUuid('card_stack_id')
                ->constrained('card_stacks')
                ->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['deck_card_id', 'card_stack_id']);
            $table->index(['card_stack_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deck_card_card_stack');
    }
};
