<?php

namespace App\Models;

use App\Enums\CardLanguage;
use App\Enums\DeckCardRole;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Services\DeckCardCopiesService;
use Illuminate\Database\Eloquent\Builder;
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
        'role',
        'quantity',
        'finish',
        'language',
        'proxy',
    ];

    protected function casts(): array
    {
        return [
            'zone' => DeckZone::class,
            'role' => DeckCardRole::class,
            'quantity' => 'integer',
            'finish' => Finish::class,
            'language' => CardLanguage::class,
            'proxy' => 'boolean',
        ];
    }

    /**
     * `finish`, `language` and `proxy` are the plan for the copies of this
     * card that are not in the collection yet; a claimed copy's values live
     * on its card stack (see {@see DeckCardCopiesService}).
     *
     * Every writer gets the same two guarantees here, so none of them has to
     * know: a new row is planned in English and not as a proxy, and the
     * finish always exists for the row's printing — the one asked for when
     * the printing has it, the printing's first finish otherwise
     * ({@see Finish::fitTo}). That covers a foil-only printing, and a printing
     * switch onto one that lacks the planned finish.
     */
    protected static function booted(): void
    {
        static::saving(function (DeckCard $deckCard): void {
            if (! $deckCard->exists) {
                $deckCard->language ??= CardLanguage::En;
                $deckCard->proxy ??= false;
            }
            if (! $deckCard->exists || $deckCard->isDirty(['default_card_id', 'finish'])) {
                $mask = DefaultCard::query()->whereKey($deckCard->default_card_id)->value('finishes');
                $deckCard->finish = Finish::fitTo((int) ($mask ?? 0), $deckCard->finish);
            }
        });
    }

    /**
     * The plan values, for a row split off or merged from this one.
     *
     * @return array{finish: Finish, language: CardLanguage, proxy: bool}
     */
    public function planAttributes(): array
    {
        return [
            'finish' => $this->finish,
            'language' => $this->language,
            'proxy' => $this->proxy,
        ];
    }

    /**
     * Mainboard / sideboard / maybeboard rows (anything without a special role).
     *
     * @param  Builder<DeckCard>  $query
     */
    public function scopeRoleless(Builder $query): void
    {
        $query->whereNull('role');
    }

    /**
     * Command-zone rows: commander / partner / signature spell.
     *
     * @param  Builder<DeckCard>  $query
     */
    public function scopeCommandZone(Builder $query): void
    {
        $query->where('zone', DeckZone::Command->value);
    }

    /**
     * The deck's primary commander row, if any.
     *
     * @param  Builder<DeckCard>  $query
     */
    public function scopePrimaryCommander(Builder $query): void
    {
        $query->where('role', DeckCardRole::Commander->value);
    }

    /**
     * The deck's companion row, if any.
     *
     * @param  Builder<DeckCard>  $query
     */
    public function scopeCompanion(Builder $query): void
    {
        $query->where('role', DeckCardRole::Companion->value);
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
