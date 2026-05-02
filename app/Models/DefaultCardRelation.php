<?php

namespace App\Models;

use App\Enums\Scryfall\ScryfallRelatedComponent;
use App\Services\Scryfall\DefaultCardsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Printing-level edge between two default_cards, captured from
 * Scryfall's `all_parts` array. The pair is keyed at the printing
 * layer (not oracle) so the deck view can show the matching token
 * printing for the deck's chosen card printing.
 *
 * Composite primary key: (source, related, component). No surrogate
 * id, no timestamps — populated via bulk insert from
 * {@see DefaultCardsService}.
 */
class DefaultCardRelation extends Model
{
    protected $table = 'default_card_relations';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'source_default_card_id',
        'related_default_card_id',
        'component',
    ];

    protected function casts(): array
    {
        return [
            'component' => ScryfallRelatedComponent::class,
        ];
    }

    /**
     * @return BelongsTo<DefaultCard, DefaultCardRelation>
     */
    public function sourceCard(): BelongsTo
    {
        return $this->belongsTo(DefaultCard::class, 'source_default_card_id');
    }

    /**
     * @return BelongsTo<DefaultCard, DefaultCardRelation>
     */
    public function relatedCard(): BelongsTo
    {
        return $this->belongsTo(DefaultCard::class, 'related_default_card_id');
    }
}
