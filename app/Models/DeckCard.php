<?php

namespace App\Models;

use App\Enums\CardLanguage;
use App\Enums\DeckZone;
use App\Enums\Finish;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeckCard extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'deck_cards';

    protected $primaryKey = 'id';

    protected $fillable = [
        'deck_id',
        'oracle_card_id',
        'default_card_id',
        'category_id',
        'zone',
        'quantity',
        'finish',
        'language',
    ];

    protected function casts(): array
    {
        return [
            'zone' => DeckZone::class,
            'finish' => Finish::class,
            'language' => CardLanguage::class,
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Deck, DeckCard>
     */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    /**
     * The oracle card (logical identity) this deck card references.
     *
     * @return BelongsTo<OracleCard, DeckCard>
     */
    public function oracleCard(): BelongsTo
    {
        return $this->belongsTo(OracleCard::class);
    }

    /**
     * The specific printing this deck card references.
     *
     * @return BelongsTo<DefaultCard, DeckCard>
     */
    public function defaultCard(): BelongsTo
    {
        return $this->belongsTo(DefaultCard::class);
    }

    /**
     * Physical card stacks claimed for this deck slot.
     *
     * Many-to-many: multiple stacks can back a single deck_card row (e.g.
     * 4× Lightning Bolt covered by stacks of 2 + 1 + 1) and a stack may
     * theoretically back multiple deck_cards (the UX assumes one, but the
     * schema allows it).
     *
     * @return BelongsToMany<CardStack>
     */
    public function cardStacks(): BelongsToMany
    {
        return $this->belongsToMany(CardStack::class, 'deck_card_card_stack')
            ->withPivot('created_at');
    }

    /**
     * The user-defined category this card is assigned to, if any.
     *
     * @return BelongsTo<DeckCategory, DeckCard>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DeckCategory::class);
    }
}
