<?php

namespace App\Models;

use App\Enums\CardFormat;
use App\Enums\ContainerVisibility;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
use App\Enums\DeckZone;
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
        'bracket',
        'default_card_id',
        'container_id',
        'collection_mode',
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
     * Clear the hero image when its printing is no longer attached to
     * the deck in any role. Single source of truth post-consolidation:
     * every card in the deck (mainboard, sideboard, command zone,
     * companion) lives in `deck_cards`, so a single existence check
     * suffices.
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

        $isAttached = DeckCard::query()
            ->where('deck_id', $this->id)
            ->where('default_card_id', $this->default_card_id)
            ->exists();

        if (! $isAttached) {
            $this->update(['default_card_id' => null]);
        }
    }
}
