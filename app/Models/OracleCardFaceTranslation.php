<?php

namespace App\Models;

use App\Enums\Scryfall\ScryfallLang;
use App\Services\Scryfall\TranslationsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Face-level translation of a card-face name into a non-English
 * language. Exists for multi-faced layouts (transform, MDFC, split,
 * adventure, etc.) where each face has its own printed name.
 *
 * Populated by {@see TranslationsService} from
 * Scryfall's `all_cards` bulk export. Read by the search services so
 * a user searching "Erfindergeist" (the German front face of
 * Daxos, Blessed by the Sun) finds the oracle.
 *
 * One row per `(oracle_card_id, face_index, lang)` — composite PK,
 * no surrogate id.
 */
class OracleCardFaceTranslation extends Model
{
    protected $table = 'oracle_card_face_translations';

    /**
     * Composite primary key — see sibling note in
     * {@see OracleCardTranslation}.
     */
    protected $primaryKey = null;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Disable timestamps. Populated via bulk Scryfall import.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'oracle_card_id',
        'face_index',
        'lang',
        'printed_name',
        'searchable_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'face_index' => 'integer',
            'lang' => ScryfallLang::class,
        ];
    }

    /**
     * Parent oracle card this face translation belongs to.
     *
     * The FK is keyed only on `oracle_card_id` (not the composite
     * `(oracle_card_id, face_index)`) — see the migration docblock
     * for the rationale.
     *
     * @return BelongsTo<OracleCard, OracleCardFaceTranslation>
     */
    public function oracleCard(): BelongsTo
    {
        return $this->belongsTo(OracleCard::class, 'oracle_card_id');
    }
}
