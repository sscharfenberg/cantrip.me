<?php

namespace App\Models;

use App\Enums\CardFormat;
use App\Enums\ContainerVisibility;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Services\DeckCardSearchService;
use App\Services\DeckValidator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Deck extends Model
{
    use HasUuids;

    const NAME_MAX = 128;

    const DESCRIPTION_MAX = 10000;

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
        'rulebreaker_color',
        'bracket',
        'default_card_id',
        'container_id',
        'collection_mode',
    ];

    /**
     * Model-level defaults applied to fresh instances before insert. The
     * `collection_mode` default mirrors the migration's column default so
     * the in-memory model agrees with the persisted row when `Deck::create`
     * is called without an explicit mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'collection_mode' => 'A',
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
     * @return HasMany<DeckCard>
     */
    public function deckCards(): HasMany
    {
        return $this->hasMany(DeckCard::class);
    }

    /**
     * Command-zone deck_cards (zone=command). Ordered with the primary
     * commander first, secondary slot (partner / signature spell) second.
     * Eager-loadable via `with('commanders')` — same call site as before
     * the consolidation.
     *
     * @return HasMany<DeckCard>
     */
    public function commanders(): HasMany
    {
        return $this->hasMany(DeckCard::class)
            ->where('zone', DeckZone::Command->value)
            ->orderByRaw("CASE role WHEN 'commander' THEN 0 WHEN 'partner' THEN 1 WHEN 'signature_spell' THEN 1 ELSE 2 END");
    }

    /**
     * The deck's primary commander row, if any.
     *
     * @return HasOne<DeckCard>
     */
    public function primaryCommander(): HasOne
    {
        return $this->hasOne(DeckCard::class)
            ->where('role', DeckCardRole::Commander->value);
    }

    /**
     * The deck's Magic-keyword companion row, if any. Eager-loadable.
     *
     * @return HasOne<DeckCard>
     */
    public function companion(): HasOne
    {
        return $this->hasOne(DeckCard::class)
            ->where('role', DeckCardRole::Companion->value);
    }

    /**
     * @return HasMany<DeckCategory>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(DeckCategory::class);
    }

    /**
     * Swap the hero printing if it currently matches `$from`.
     *
     * Used during printing-swap flows: when a deck card / commander /
     * companion changes its `default_card_id`, the hero (if pinned to
     * the old printing) follows the swap instead of being orphaned.
     */
    public function remapHeroImage(string $from, string $to): void
    {
        if ($this->default_card_id === $from && $from !== $to) {
            $this->update(['default_card_id' => $to]);
        }
    }

    /**
     * Is `$defaultCardId` attached to this deck in any role?
     *
     * Single source of truth post-consolidation: every card in the deck
     * (mainboard, sideboard, maybeboard, command zone, companion) lives in
     * `deck_cards`, so one existence check answers for all of them.
     *
     * Shared with the deck-update hero-image validator so the two can't
     * drift — they did once, when the consolidation left the validator
     * probing the since-dropped `commanders` table.
     */
    public function carriesPrinting(string $defaultCardId): bool
    {
        return DeckCard::query()
            ->where('deck_id', $this->id)
            ->where('default_card_id', $defaultCardId)
            ->exists();
    }

    /**
     * The colour identity this deck's cards are judged against — the single
     * source for both the legality check and the card-search filter.
     *
     * Those two used to disagree. {@see DeckValidator} derived
     * the identity from the command zone, while
     * {@see DeckCardSearchService} read the `colors` column, and
     * the column is not the same thing:
     * `DeckCardService::recalculateColorsFromCommandZone()` folds the
     * COMPANION's colours in as well. So a deck with a companion could be
     * offered cards in search that the validator then flagged, and neither was
     * obviously wrong on its own. No deck in production has ever hit it —
     * there are no companion decks yet — which is precisely why it was worth
     * closing before something depended on the discrepancy.
     *
     * `colors` wins because it is the denormalised value the rest of the app
     * already treats as the deck's identity (stats, the header, the deck
     * payload). Deriving instead would mean recomputing it on every search
     * keystroke.
     *
     * **The companion union is safe, but only because something else makes it
     * so.** Unifying on a column that includes the companion's colours would
     * otherwise widen the legality check — a red commander with a white-black
     * companion would give `colors = 'WBR'`, and every white and black card in
     * the deck would then pass. It cannot arise because
     * `SetDeckCompanionRequest` rejects a companion whose identity is not
     * within the commander's, so the union can only ever equal the commander's
     * identity. That is load-bearing: if that validation is ever relaxed, or if
     * an import path writes a companion without it, this accessor starts
     * reporting a wider identity than the command zone justifies and the
     * derivation below should be made unconditional.
     *
     * **Why the fallback.** The column is NULL both for a deck with no command
     * zone and for one whose commander is genuinely colourless — the recalc
     * writes `$merged ?: null` and cannot distinguish them. Treating NULL as
     * "colourless" outright would be right for the second case and badly wrong
     * for a deck whose column was never populated: every coloured card in it
     * would be reported as a colour-identity violation at once. Deriving from
     * the command zone in that case costs one indexed query, only when the
     * column is empty, and returns '' for a truly colourless commander anyway.
     */
    public function colorIdentity(): string
    {
        $stored = (string) ($this->colors ?? '');
        if ($stored !== '') {
            return $stored;
        }

        $letters = [];
        foreach ($this->commanders as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if ($oracle === null || $oracle->color_identity === null) {
                continue;
            }
            foreach (str_split($oracle->color_identity) as $letter) {
                $letters[$letter] = true;
            }
        }

        return implode('', array_filter(
            ['W', 'U', 'B', 'R', 'G'],
            fn (string $letter): bool => isset($letters[$letter]),
        ));
    }

    /**
     * Clear the hero image when its printing is no longer attached to
     * the deck in any role.
     *
     * The deck-update form validator requires the submitted hero to
     * match an attached printing, so any mutation that can detach the
     * current hero must call this afterwards.
     */
    public function syncHeroImage(): void
    {
        if ($this->default_card_id === null) {
            return;
        }

        if (! $this->carriesPrinting($this->default_card_id)) {
            $this->update(['default_card_id' => null]);
        }
    }
}
