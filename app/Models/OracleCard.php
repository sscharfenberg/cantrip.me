<?php

namespace App\Models;

use App\Enums\CardFormat;
use App\Enums\CardLegality;
use App\Enums\Scryfall\ScryfallCardLayout;
use App\Enums\Scryfall\ScryfallLang;
use App\Services\Scryfall\RulingsService;
use App\Services\Scryfall\TranslationsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OracleCard extends Model
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
    protected $table = 'oracle_cards';

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
        'cmc',
        'color_identity',
        'produced_mana',
        'reserved',
        'game_changer',
        'mld',
        'fetch_pattern',
        'scryfall_uri',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'reserved' => 'boolean',
        'game_changer' => 'boolean',
        'mld' => 'boolean',
        'layout' => ScryfallCardLayout::class,
        'lang' => ScryfallLang::class,
        'cmc' => 'float',
    ];

    /**
     * Get all legality entries for this oracle card.
     *
     * Formats where the card is not legal have no row — absence means not legal.
     */
    public function legalities(): HasMany
    {
        return $this->hasMany(OracleCardLegality::class, 'oracle_card_id');
    }

    /**
     * Get all default versions of this oracle card.
     */
    public function defaults(): HasMany
    {
        return $this->hasMany(DefaultCard::class, 'oracle_id');
    }

    /**
     * Get the card faces (1 for single-faced cards, 2 for multi-faced).
     *
     * @return HasMany<OracleCardFace>
     */
    public function faces(): HasMany
    {
        return $this->hasMany(OracleCardFace::class);
    }

    /**
     * Get the rulings for this oracle card. Populated from Scryfall's
     * rulings bulk export via {@see RulingsService}.
     *
     * @return HasMany<Ruling>
     */
    public function rulings(): HasMany
    {
        return $this->hasMany(Ruling::class, 'oracle_card_id');
    }

    /**
     * Get the foreign-language translations of this card's name.
     * Populated from Scryfall's `all_cards` bulk export via
     * {@see TranslationsService}. Used by the search services so
     * users can find a card by any printed-language name.
     *
     * Face-level translations are exposed via {@see faceTranslations()}.
     *
     * @return HasMany<OracleCardTranslation>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(OracleCardTranslation::class, 'oracle_card_id');
    }

    /**
     * Get the foreign-language translations of this card's face
     * names. Only populated for multi-faced layouts (transform,
     * MDFC, split, adventure, etc.) where each face has its own
     * printed name. Populated from Scryfall's `all_cards` bulk
     * export via {@see TranslationsService}.
     *
     * @return HasMany<OracleCardFaceTranslation>
     */
    public function faceTranslations(): HasMany
    {
        return $this->hasMany(OracleCardFaceTranslation::class, 'oracle_card_id');
    }

    /**
     * Whether this card's oracle text contains the "a deck can have any number
     * of cards named" clause — e.g. Rat Colony, Relentless Rats, Shadowborn
     * Apostle. These cards bypass format copy limits and the singleton rule.
     *
     * Requires the `faces` relation to be loaded with the `oracle_text` column.
     * Cards with a bounded clause ("up to seven cards named Seven Dwarves") do
     * not match — only the unbounded "any number of" wording counts.
     */
    public function hasUnlimitedCopiesRule(): bool
    {
        $this->loadMissing('faces');

        $needle = mb_strtolower("a deck can have any number of cards named {$this->name}");

        return $this->faces->contains(fn (OracleCardFace $face): bool => $face->oracle_text !== null
            && str_contains(mb_strtolower($face->oracle_text), $needle)
        );
    }

    /**
     * Restrict the query to cards that are legal (or restricted) in the given format.
     *
     * A card is considered playable when it has a legalities row for the format
     * with status `legal` or `restricted`. `banned` and missing rows are excluded.
     */
    public function scopeLegalIn(Builder $query, CardFormat $format): Builder
    {
        return $query->whereHas('legalities', function (Builder $q) use ($format): void {
            $q->where('format', $format->value)
                ->whereIn('legality', [CardLegality::Legal->value, CardLegality::Restricted->value]);
        });
    }
}
