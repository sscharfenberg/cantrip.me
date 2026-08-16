<?php

namespace App\Rulebreakers;

use App\Enums\Scryfall\ScryfallCardLayout;
use App\Formats\FormatProfile;
use App\Models\OracleCard;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * One relaxation granted by a Rulebreaker commander: a class of card, and the
 * colour identity that class is judged against instead of the deck's.
 *
 * WHY THIS EXISTS AS DATA RATHER THAN AS TWO PIECES OF CODE. The same rule has
 * to be enforced in two places — {@see RulebreakerProfile::allowedIdentityFor()}
 * decides whether a card already in the deck is legal, and the deck-card search
 * filter decides whether it is offered in the first place. Written twice they
 * drift, and the drift is invisible: search silently stops offering cards the
 * validator would accept, or offers ones it will flag. Both consumers read this
 * object instead — {@see matches()} for the row-at-a-time check, {@see applyTo()}
 * for the SQL — so a rule is defined once.
 *
 * The matchers are deliberately narrow. Between them the eight Rulebreakers ask
 * about card types ("Angel cards", "Aura cards", "artifact creature and
 * Equipment cards"), about basic lands ("any basic land cards"), and in one case
 * about mana value ("creature cards with mana value 7 or greater"). Anything a
 * future card needs that these cannot express should become another field here
 * rather than a bespoke override, so the SQL side keeps up automatically.
 */
final class RulebreakerExemption
{
    /** Every colour — "any color identity". */
    public const ANY_IDENTITY = 'WUBRG';

    /**
     * The SQL counterpart of the layout narrowing in {@see cardTypesMention()}.
     *
     * `SUBSTRING_INDEX(x, sep, 1)` returns the whole string when the separator
     * is absent, so single-faced cards need no special case.
     */
    private const CARD_TYPES_SQL = "(CASE WHEN oracle_cards.layout = 'split'
            THEN oracle_cards.type_line
            ELSE SUBSTRING_INDEX(oracle_cards.type_line, ' // ', 1)
        END)";

    /**
     * @param  string  $identity  Colour identity matching cards are judged against.
     * @param  array<int, string>  $types  Type-line needles; a card matches if ANY appears in its card types.
     * @param  bool  $basicLands  Match basic land cards by name, instead of by type.
     * @param  float|null  $minCmc  Narrow a type match to cards at or above this mana value.
     *
     * @throws InvalidArgumentException when the exemption would match on nothing.
     */
    public function __construct(
        public readonly string $identity,
        public readonly array $types = [],
        public readonly bool $basicLands = false,
        public readonly ?float $minCmc = null,
    ) {
        // An exemption with neither a type list nor the basic-land flag has no
        // subject, and the two consumers disagree about what that means:
        // matches() answers "nothing", while applyTo() would build an empty
        // nested where — which Laravel discards — leaving `cmc >= ?` alone, or
        // nothing at all, as the whole predicate. Search would then offer cards
        // the validator flags, which is precisely the drift this class exists
        // to prevent, so it is refused at construction rather than left to
        // differ at runtime. Realistic trigger: declaring Maular's "creature
        // cards with mana value 7 or greater" as a bare minCmc and forgetting
        // `types: ['Creature']`.
        if ($types === [] && ! $basicLands) {
            throw new InvalidArgumentException(
                'A RulebreakerExemption must match on card types or on basic lands; one of $types or $basicLands is required.'
            );
        }
    }

    /**
     * Whether this exemption covers the given card.
     *
     * The basic-land and type matchers are alternatives, not conjunctions —
     * "Angel cards of any color identity AND any basic land cards" is two
     * exemptions, not one, precisely so each can grant its own identity.
     */
    public function matches(OracleCard $card): bool
    {
        if ($this->basicLands) {
            return in_array($card->name, FormatProfile::BASIC_LANDS, true);
        }

        if (! $this->cardTypesMention($card)) {
            return false;
        }

        return $this->minCmc === null || (float) $card->cmc >= $this->minCmc;
    }

    /**
     * Add this exemption as one OR-branch of a colour-identity filter.
     *
     * Mirrors {@see matches()} in SQL. Both halves read the denormalised
     * `oracle_cards.type_line`, so the layout narrowing below has to agree with
     * {@see cardTypesMention()} exactly — see that method for why the whole
     * column cannot simply be matched.
     *
     * @param  Builder  $query  A query rooted at `oracle_cards`.
     */
    public function applyTo(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            if ($this->basicLands) {
                $q->whereIn('oracle_cards.name', FormatProfile::BASIC_LANDS);
            } else {
                $q->where(function (Builder $typeQuery): void {
                    foreach ($this->types as $type) {
                        // Bound as a parameter rather than interpolated: these
                        // are constants today, but a LIKE needle spliced into
                        // raw SQL is a bad habit to leave lying around.
                        $typeQuery->orWhereRaw(
                            self::CARD_TYPES_SQL.' LIKE ?',
                            ['%'.$type.'%'],
                        );
                    }
                });

                if ($this->minCmc !== null) {
                    $q->where('oracle_cards.cmc', '>=', $this->minCmc);
                }
            }

            // A blanket exemption needs no colour test at all — `^[WUBRG]*$`
            // matches every identity including the empty one, so emitting it
            // would cost a regex per row to answer "yes".
            if ($this->identity !== self::ANY_IDENTITY) {
                $safe = preg_replace('/[^WUBRG]/', '', $this->identity) ?? '';
                if ($safe === '') {
                    // Colourless: only colourless cards qualify. An empty
                    // character class is invalid regex, so this is expressed
                    // directly.
                    $q->where(function (Builder $c): void {
                        $c->whereNull('oracle_cards.color_identity')
                            ->orWhere('oracle_cards.color_identity', '');
                    });
                } else {
                    $q->where(function (Builder $c) use ($safe): void {
                        $c->whereNull('oracle_cards.color_identity')
                            ->orWhere('oracle_cards.color_identity', '')
                            ->orWhereRaw('oracle_cards.color_identity REGEXP ?', ['^['.$safe.']*$']);
                    });
                }
            }
        });
    }

    /**
     * The card's own types, narrowed to the faces that determine them.
     *
     * `type_line` joins every face with " // ". A split card genuinely has both
     * halves' types — Fire // Ice is an instant card by either — but every
     * other multi-faced layout takes its types from the front face alone.
     * Bonecrusher Giant is "Creature — Giant // Instant — Adventure" and is a
     * CREATURE card; its Adventure half is an alternative characteristic used
     * only on the stack.
     *
     * This is not a nicety: of the 170 Adventure cards in the dataset, matching
     * the whole line for "Instant|Sorcery" hits all 170, while matching the
     * front face hits 1. Without the narrowing a Tolabow deck nominating red
     * would be handed every red Adventure creature in Magic.
     */
    private function cardTypesMention(OracleCard $card): bool
    {
        $line = (string) ($card->type_line ?? '');
        if ($line === '') {
            return false;
        }

        $faces = explode(' // ', $line);
        $relevant = $card->layout === ScryfallCardLayout::Split ? $faces : [$faces[0]];

        foreach ($relevant as $face) {
            foreach ($this->types as $type) {
                // Case-INSENSITIVE, to match the `LIKE` in applyTo() running
                // under MySQL's default case-insensitive collation. Scryfall
                // type lines are always title-cased so this changes nothing
                // today, but the whole point of this class is that the two
                // consumers cannot disagree, and case was one axis on which
                // they still could: a needle written in lowercase would have
                // been honoured by search and ignored by the validator.
                if (mb_stripos($face, $type) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
