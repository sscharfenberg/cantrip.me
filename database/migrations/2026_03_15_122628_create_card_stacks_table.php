<?php

use App\Enums\CardLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_stacks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUuid('default_card_id')
                ->constrained('default_cards')
                ->cascadeOnDelete();
            $table->foreignUuid('container_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('amount')->default(1);
            $table->string('condition', 16)->nullable();
            $table->unsignedTinyInteger('finish')->default(1);
            $table->string('language', 3)->default(CardLanguage::En->value);
            // Storage-only flag for proxy cards. UI surfacing (toggle in the
            // create/edit form, badge in the collection view, status badge
            // semantics in modes B/C, price-aggregation handling) is a
            // separate follow-up — see PLAN doc / project memory.
            $table->boolean('proxy')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'container_id']);
            $table->index(['default_card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_stacks');
    }
};
