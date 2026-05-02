<?php

namespace App\Models;

use App\Enums\Scryfall\ScryfallCardLayout;
use App\Enums\Scryfall\ScryfallLang;
use App\Enums\Scryfall\ScryfallRarity;
use App\Enums\Scryfall\ScryfallRelatedComponent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DefaultCard extends Model
{
    use HasUuids;

    /**
     * The data type of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'default_cards';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Disable use of timestamps. Since we do a full DB insert, we do not need timestamps
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'name',
        'searchable_name',
        'collector_number',
        'layout',
        'lang',
        'card_image_0',
        'card_image_1',
        'art_crop',
        'finishes',
        'games',
        'price_usd',
        'price_usd_foil',
        'price_usd_etched',
        'price_eur',
        'price_eur_foil',
        'price_eur_etched',
        'digital',
        'rarity',
        'artist_id',
        'set_id',
        'oracle_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'layout' => ScryfallCardLayout::class,
        'lang' => ScryfallLang::class,
        'rarity' => ScryfallRarity::class,
        'art_crop' => 'string',
        'finishes' => 'integer',
        'games' => 'integer',
        'price_usd' => 'decimal:2',
        'price_usd_foil' => 'decimal:2',
        'price_usd_etched' => 'decimal:2',
        'price_eur' => 'decimal:2',
        'price_eur_foil' => 'decimal:2',
        'price_eur_etched' => 'decimal:2',
        'digital' => 'boolean',
    ];

    /**
     * Get the oracle card associated with this default card.
     *
     * @return BelongsTo<OracleCard, DefaultCard>
     */
    public function oracle(): BelongsTo
    {
        return $this->belongsTo(OracleCard::class, 'oracle_id', 'id');
    }

    /**
     * Get the set this default card belongs to.
     *
     * @return BelongsTo<Set, DefaultCard>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class, 'set_id', 'id');
    }

    /**
     * Get the artist associated with this default card.
     *
     * @return BelongsTo<Artist, DefaultCard>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id', 'id');
    }

    /**
     * All printing-level edges out of this card (tokens it creates,
     * meld parts/results it pairs with, combo pieces). Captured from
     * Scryfall's `all_parts` array per default-card so the matching
     * printing is preserved (MM2 Bitterblossom → MM2 Faerie Rogue).
     *
     * @return HasMany<DefaultCardRelation>
     */
    public function relations(): HasMany
    {
        return $this->hasMany(DefaultCardRelation::class, 'source_default_card_id');
    }

    /**
     * Token printings created by this card.
     *
     * @return HasMany<DefaultCardRelation>
     */
    public function tokens(): HasMany
    {
        return $this->relations()->where('component', ScryfallRelatedComponent::Token->value);
    }

    /**
     * Meld pieces this card pairs with (front-half cards in a meld pair).
     *
     * @return HasMany<DefaultCardRelation>
     */
    public function meldParts(): HasMany
    {
        return $this->relations()->where('component', ScryfallRelatedComponent::MeldPart->value);
    }

    /**
     * Meld result this card produces when melded.
     *
     * @return HasMany<DefaultCardRelation>
     */
    public function meldResults(): HasMany
    {
        return $this->relations()->where('component', ScryfallRelatedComponent::MeldResult->value);
    }

    /**
     * Cards this card combos with (Scryfall's "combo_piece" component).
     *
     * @return HasMany<DefaultCardRelation>
     */
    public function comboPieces(): HasMany
    {
        return $this->relations()->where('component', ScryfallRelatedComponent::ComboPiece->value);
    }
}
