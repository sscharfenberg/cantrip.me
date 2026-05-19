<?php

namespace App\Models;

use App\Enums\Scryfall\ScryfallLang;
use App\Services\Scryfall\TranslationsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oracle-level translation of a card name into a non-English language.
 *
 * Populated by {@see TranslationsService} from
 * Scryfall's `all_cards` bulk export. Read by the search services
 * (deck card-add, collection-add, commander picker) so users can
 * find a card by any printed-language name (e.g. "Blitzschlag"
 * → Lightning Bolt).
 *
 * One row per `(oracle_card_id, lang)` — composite PK, no surrogate
 * id, no auto-incrementing key. Dedupe across reprints happens in
 * memory during the streaming parse.
 */
class OracleCardTranslation extends Model
{
    protected $table = 'oracle_card_translations';

    /**
     * Composite primary key — Eloquent treats `$primaryKey` as a single
     * column when using a string, and as a composite when given an
     * array. We override `getKeyName()` and `getKeyType()` indirectly
     * by leaving `$primaryKey` unset and relying on `incrementing = false`.
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
            'lang' => ScryfallLang::class,
        ];
    }

    /**
     * Parent oracle card this translation belongs to.
     *
     * @return BelongsTo<OracleCard, OracleCardTranslation>
     */
    public function oracleCard(): BelongsTo
    {
        return $this->belongsTo(OracleCard::class, 'oracle_card_id');
    }
}
