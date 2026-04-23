<?php

namespace App\Models;

use App\Enums\CardFormat;
use App\Enums\ContainerVisibility;
use App\Enums\DeckState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deck extends Model
{
    use HasUuids;

    const NAME_MAX = 128;

    const DESCRIPTION_MAX = 2000;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'decks';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'format',
        'visibility',
        'state',
        'colors',
        'bracket',
        'default_card_id',
        'container_id',
        'companion_oracle_card_id',
        'companion_default_card_id',
    ];

    protected function casts(): array
    {
        return [
            'format' => CardFormat::class,
            'visibility' => ContainerVisibility::class,
            'state' => DeckState::class,
            'bracket' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, Deck>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The default card used as the deck's hero/banner art.
     *
     * @return BelongsTo<DefaultCard, Deck>
     */
    public function defaultCard(): BelongsTo
    {
        return $this->belongsTo(DefaultCard::class, 'default_card_id');
    }

    /**
     * The container (deckbox) this deck is stored in when built.
     *
     * @return BelongsTo<Container, Deck>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * The commander cards for this deck (Commander/Oathbreaker/Brawl formats).
     *
     * Pivots on oracle_card_id (logical identity) and includes default_card_id
     * (specific printing for display).
     *
     * @return BelongsToMany<OracleCard>
     */
    public function commanders(): BelongsToMany
    {
        return $this->belongsToMany(OracleCard::class, 'commanders', 'deck_id', 'oracle_card_id')
            ->using(Commander::class)
            ->withPivot('default_card_id', 'is_partner')
            ->withTimestamps();
    }

    /**
     * The Magic "Companion" keyword card selected for this deck (Lurrus, Yorion, …).
     *
     * Distinct from command-zone partner pairings stored on the `commanders` pivot.
     *
     * @return BelongsTo<OracleCard, Deck>
     */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(OracleCard::class, 'companion_oracle_card_id');
    }

    /**
     * The specific printing chosen for the companion (for display).
     *
     * @return BelongsTo<DefaultCard, Deck>
     */
    public function companionDefaultCard(): BelongsTo
    {
        return $this->belongsTo(DefaultCard::class, 'companion_default_card_id');
    }

    /**
     * @return HasMany<DeckCard>
     */
    public function deckCards(): HasMany
    {
        return $this->hasMany(DeckCard::class);
    }

    /**
     * @return HasMany<DeckCategory>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(DeckCategory::class);
    }
}
