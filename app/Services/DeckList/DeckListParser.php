<?php

namespace App\Services\DeckList;

use App\Models\DeckCategory;

/**
 * Turns a pasted deck list into structured lines — no database access.
 *
 * Deck-building sites all copy a deck to the clipboard as one card per line
 * with optional section headers mixed in, but each with its own dialect:
 * Moxfield's bulk edit has no headers and no commander, Draftsim has card
 * type headlines and no printings, MTGA wraps the list in `About` / `Deck` /
 * `Sideboard`, MTGO separates the sideboard with nothing but a blank line,
 * and cantrip.me's own print view (`resources/app/utils/printableDeck.ts`)
 * puts a title block and dividers around translated group headlines.
 *
 * Rather than detect the site, every line is classified on its own:
 *
 *  - **card line** — `[qty][x] Name [(SET) [number]]` plus the markers sites
 *    append: Archidekt `[Category]` and `^label^`, Moxfield `#tag` and
 *    `*F*` / `*E*` finishes, MTGO's `SB:` prefix;
 *  - **section header** — sets the zone, or the custom category, for the
 *    card lines below it;
 *  - **noise** — blank lines, dividers, comments, a title block.
 *
 * A line is a header only when it is marked as one: a known header word
 * ({@see self::HEADER_WORDS}), a leading `//`, a trailing `:` or `(n)`, or a
 * divider line directly below it. An unmarked line without a quantity is a
 * card line with quantity 1 — a stray title then fails to resolve and the
 * user excludes it on the review page, where guessing the other way would
 * silently turn `Sol Ring` into a category.
 *
 * Kept free of the database so the dialect rules are unit-tested against
 * fixture files; resolving names to cards is {@see DeckListResolver}'s job.
 */
final class DeckListParser
{
    public const ZONE_MAIN = 'main';

    public const ZONE_SIDE = 'side';

    public const ZONE_COMPANION = 'companion';

    public const ZONE_COMMAND = 'command';

    /** Header kind: the lines below are skipped and counted (maybeboard). */
    private const KIND_DROP = 'drop';

    /** Header kind: the lines below are skipped silently (MTGA `About`, tokens). */
    private const KIND_IGNORE = 'ignore';

    /** Header kind: a card-type headline — main zone, no category. */
    private const KIND_TYPE = 'type';

    /** Largest quantity accepted on one line; anything above is a typo, not a deck. */
    private const MAX_QUANTITY = 999;

    /**
     * Known header words, lower-cased, mapped to what they mean. English and
     * German, because cantrip.me's own print view emits its group labels in
     * the viewer's locale and has to import back losslessly.
     *
     * The type words double as category names that carry no category — see
     * {@see self::categoryKind()} — mirroring `ArchidektDeckMapper`, which
     * drops the same names on CSV import because the deck page groups by
     * card type on its own.
     *
     * @var array<string, string>
     */
    private const HEADER_WORDS = [
        'commander' => self::ZONE_COMMAND,
        'commanders' => self::ZONE_COMMAND,
        'command' => self::ZONE_COMMAND,
        'command zone' => self::ZONE_COMMAND,
        'oathbreaker' => self::ZONE_COMMAND,
        'signature spell' => self::ZONE_COMMAND,
        'kommandozone' => self::ZONE_COMMAND,
        'kommandeur' => self::ZONE_COMMAND,
        'kommandeure' => self::ZONE_COMMAND,
        'companion' => self::ZONE_COMPANION,
        'companions' => self::ZONE_COMPANION,
        'begleiter' => self::ZONE_COMPANION,
        'sideboard' => self::ZONE_SIDE,
        'side board' => self::ZONE_SIDE,
        'side' => self::ZONE_SIDE,
        'deck' => self::ZONE_MAIN,
        'main' => self::ZONE_MAIN,
        'mainboard' => self::ZONE_MAIN,
        'main deck' => self::ZONE_MAIN,
        'maindeck' => self::ZONE_MAIN,
        'maybeboard' => self::KIND_DROP,
        'maybe board' => self::KIND_DROP,
        'maybe' => self::KIND_DROP,
        'considering' => self::KIND_DROP,
        'vielleicht' => self::KIND_DROP,
        'about' => self::KIND_IGNORE,
        'token' => self::KIND_IGNORE,
        'tokens' => self::KIND_IGNORE,
        'creature' => self::KIND_TYPE,
        'creatures' => self::KIND_TYPE,
        'planeswalker' => self::KIND_TYPE,
        'planeswalkers' => self::KIND_TYPE,
        'battle' => self::KIND_TYPE,
        'battles' => self::KIND_TYPE,
        'artifact' => self::KIND_TYPE,
        'artifacts' => self::KIND_TYPE,
        'enchantment' => self::KIND_TYPE,
        'enchantments' => self::KIND_TYPE,
        'instant' => self::KIND_TYPE,
        'instants' => self::KIND_TYPE,
        'sorcery' => self::KIND_TYPE,
        'sorceries' => self::KIND_TYPE,
        'land' => self::KIND_TYPE,
        'lands' => self::KIND_TYPE,
        'other' => self::KIND_TYPE,
        'kreatur' => self::KIND_TYPE,
        'kreaturen' => self::KIND_TYPE,
        'schlacht' => self::KIND_TYPE,
        'schlachten' => self::KIND_TYPE,
        'artefakt' => self::KIND_TYPE,
        'artefakte' => self::KIND_TYPE,
        'verzauberung' => self::KIND_TYPE,
        'verzauberungen' => self::KIND_TYPE,
        'spontanzauber' => self::KIND_TYPE,
        'hexerei' => self::KIND_TYPE,
        'hexereien' => self::KIND_TYPE,
        'länder' => self::KIND_TYPE,
        'sonstige' => self::KIND_TYPE,
    ];

    /**
     * @return array{
     *     sections: list<array{index: int, label: string|null, zone: string, zone_guessed: bool}>,
     *     lines: list<array{line: int, raw: string, section: int, zone: string, zone_guessed: bool, quantity: int, name: string|null, set: string|null, number: string|null, category: string|null}>,
     *     dropped: int,
     * }
     */
    public static function parse(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $rawLines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $trimmed = array_map(fn (string $line): string => trim($line), $rawLines);

        $preambleEnd = self::preambleEnd($trimmed);

        $sections = [];
        $lines = [];
        $dropped = 0;
        $headerSeen = false;

        // The section card lines are currently written into. -1 until the
        // first card line or header opens one, so leading noise does not
        // leave an empty section behind.
        $current = -1;
        $mode = null;
        $zone = self::ZONE_MAIN;
        $category = null;
        $blankSinceCard = false;

        foreach ($trimmed as $index => $line) {
            if ($index <= $preambleEnd) {
                continue;
            }

            if ($line === '') {
                $blankSinceCard = $current >= 0;

                continue;
            }

            if (self::isDivider($line) || str_starts_with($line, '#')) {
                continue;
            }

            $header = self::header($line, $trimmed[$index + 1] ?? '');
            if ($header !== null) {
                if ($header['comment']) {
                    continue;
                }
                $headerSeen = true;
                [$mode, $zone, $category] = self::applyHeader($header['label']);
                $sections[] = ['index' => count($sections), 'label' => $header['label'], 'zone' => $zone, 'zone_guessed' => false];
                $current = count($sections) - 1;
                $blankSinceCard = false;

                continue;
            }

            if ($mode === self::KIND_IGNORE) {
                continue;
            }
            if ($mode === self::KIND_DROP) {
                $dropped++;

                continue;
            }

            // Without headers a blank line is the only structure there is —
            // MTGO and MTGA put the sideboard after one. Each block becomes
            // its own section so the guess below can address it.
            if ($current < 0 || (! $headerSeen && $blankSinceCard)) {
                $sections[] = ['index' => count($sections), 'label' => null, 'zone' => $zone, 'zone_guessed' => false];
                $current = count($sections) - 1;
            }
            $blankSinceCard = false;

            $card = self::cardLine($line);
            if ($card['drop']) {
                $dropped++;

                continue;
            }

            $lineZone = $card['zone'] ?? $zone;
            $lineCategory = $card['category_given'] ? $card['category'] : $category;

            $lines[] = [
                'line' => $index + 1,
                'raw' => $line,
                'section' => $current,
                'zone' => $lineZone,
                'zone_guessed' => false,
                'quantity' => $card['quantity'],
                'name' => $card['name'],
                'set' => $card['set'],
                'number' => $card['number'],
                'category' => in_array($lineZone, [self::ZONE_MAIN, self::ZONE_SIDE], true) ? $lineCategory : null,
            ];
        }

        // Two header-less blocks: the second is the sideboard, MTGO-style.
        // Only a guess — flagged so the review page offers a zone choice.
        if (! $headerSeen && count($sections) === 2) {
            $sections[1]['zone'] = self::ZONE_SIDE;
            $sections[1]['zone_guessed'] = true;
            foreach ($lines as $i => $line) {
                if ($line['section'] === 1 && $line['zone'] === self::ZONE_MAIN) {
                    $lines[$i]['zone'] = self::ZONE_SIDE;
                    $lines[$i]['zone_guessed'] = true;
                }
            }
        }

        return ['sections' => $sections, 'lines' => $lines, 'dropped' => $dropped];
    }

    /**
     * Index of the last line of a title block, or -1 when there is none.
     *
     * cantrip.me's print view opens with the deck name, description and
     * metadata, each block closed by a `====` divider, before the first card.
     * Everything up to the last such divider above the first quantity line is
     * a title block. Lists without `====` dividers have no title block.
     *
     * @param  list<string>  $lines
     */
    private static function preambleEnd(array $lines): int
    {
        $end = -1;
        foreach ($lines as $index => $line) {
            if (self::hasQuantity($line)) {
                break;
            }
            if (preg_match('/^={3,}$/', $line) === 1) {
                $end = $index;
            }
        }

        return $end;
    }

    /**
     * Classify a line as a header, if it is one.
     *
     * Returns `comment` for a `//` line that carries no other header mark —
     * `// Creatures` is a header, `// built for FNM` is a remark.
     *
     * @return array{label: string, comment: bool}|null
     */
    private static function header(string $line, string $nextLine): ?array
    {
        if (self::hasQuantity($line) || preg_match('/^SB:/i', $line) === 1) {
            return null;
        }

        $label = $line;
        $slashes = str_starts_with($label, '//');
        if ($slashes) {
            $label = ltrim(substr($label, 2));
        }
        $colon = str_ends_with($label, ':');
        if ($colon) {
            $label = rtrim(substr($label, 0, -1));
        }
        $count = preg_match('/\s*\(\d+\)$/', $label) === 1;
        if ($count) {
            $label = rtrim((string) preg_replace('/\s*\(\d+\)$/', '', $label));
        }
        if ($label === '') {
            return $slashes ? ['label' => '', 'comment' => true] : null;
        }

        $known = isset(self::HEADER_WORDS[self::key($label)]);
        $dividerBelow = self::isDivider($nextLine);

        if ($known || $colon || $count || $dividerBelow) {
            return ['label' => $label, 'comment' => false];
        }

        return $slashes ? ['label' => $label, 'comment' => true] : null;
    }

    /**
     * What a header switches to: the skip mode, the zone and the category
     * for the lines below it. An unknown header is a custom category.
     *
     * @return array{0: string|null, 1: string, 2: string|null}
     */
    private static function applyHeader(string $label): array
    {
        $kind = self::HEADER_WORDS[self::key($label)] ?? null;

        return match ($kind) {
            self::KIND_DROP, self::KIND_IGNORE => [$kind, self::ZONE_MAIN, null],
            self::KIND_TYPE, self::ZONE_MAIN => [null, self::ZONE_MAIN, null],
            self::ZONE_SIDE, self::ZONE_COMPANION, self::ZONE_COMMAND => [null, $kind, null],
            default => [null, self::ZONE_MAIN, self::categoryName($label)],
        };
    }

    /**
     * Parse one card line.
     *
     * `zone` is set only when the line itself decides it (`SB:` prefix, or a
     * category that names a zone); otherwise the section's zone applies.
     * `category_given` distinguishes "no inline category" (inherit the
     * section's) from "an inline category that resolves to none" (a type
     * name — the line has no category even under a custom header).
     *
     * @return array{quantity: int, name: string|null, set: string|null, number: string|null, zone: string|null, category: string|null, category_given: bool, drop: bool}
     */
    private static function cardLine(string $line): array
    {
        $zone = null;
        if (preg_match('/^SB:\s*/i', $line, $match) === 1) {
            $zone = self::ZONE_SIDE;
            $line = substr($line, strlen($match[0]));
        }

        $categories = [];
        $noDeck = false;
        // Archidekt: `[Ramp,Artifact{noDeck}]` — the first is the card's
        // primary category; `{flags}` say how Archidekt treats the category.
        if (preg_match('/\[([^\]]*)\]/', $line, $match) === 1) {
            $noDeck = stripos($match[1], '{noDeck}') !== false;
            $plain = (string) preg_replace('/\{[^}]*\}/', '', $match[1]);
            $categories = array_values(array_filter(array_map('trim', explode(',', $plain)), fn (string $c): bool => $c !== ''));
        }
        $line = (string) preg_replace('/\[[^\]]*\]/', ' ', $line);

        // Moxfield: `#Ramp #!Card Draw` — one tag runs until the next ` #`.
        if (preg_match_all('/(?:^|\s)#!?([^#]+?)(?=\s+#|$)/', $line, $matches) > 0) {
            foreach ($matches[1] as $tag) {
                $categories[] = trim($tag);
            }
            $line = (string) preg_replace('/(?:^|\s)#!?[^#]+?(?=\s+#|$)/', ' ', $line);
        }

        // Archidekt `^Have,#37d67a^` labels and `*F*` / `*E*` finish markers.
        $line = (string) preg_replace('/\^[^^]*\^|\*[A-Za-z]{1,3}\*/', ' ', $line);
        $line = trim((string) preg_replace('/\s+/', ' ', $line));

        $quantity = 1;
        if (preg_match('/^(\d+)\s*[xX]?\s+(.*)$/', $line, $match) === 1) {
            $quantity = (int) $match[1];
            $line = $match[2];
        }

        $set = null;
        $number = null;
        if (preg_match('/^(.*?)\s+\(([A-Za-z0-9]{2,6})\)(?:\s+([A-Za-z0-9★†.\-]+))?$/u', $line, $match) === 1) {
            $line = $match[1];
            $set = strtolower($match[2]);
            $number = ($match[3] ?? '') !== '' ? $match[3] : null;
        }

        $name = trim($line);
        if ($name === '' || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
            $name = null;
        }

        $category = null;
        $categoryGiven = $categories !== [];
        $drop = false;
        if ($categoryGiven) {
            $kind = self::categoryKind($categories[0]);
            if ($kind === self::KIND_DROP || $kind === self::KIND_IGNORE) {
                $drop = true;
            } elseif (in_array($kind, [self::ZONE_COMMAND, self::ZONE_SIDE, self::ZONE_COMPANION], true)) {
                $zone ??= $kind;
            } elseif ($kind === null) {
                // Archidekt marks out-of-deck categories {noDeck}. The named
                // ones (Sideboard, Maybeboard) are handled above; any other
                // flagged category is a wishlist of cards not in the deck.
                if ($noDeck) {
                    $drop = true;
                } else {
                    $category = self::categoryName($categories[0]);
                }
            }
        }

        return [
            'quantity' => $quantity,
            'name' => $name,
            'set' => $set,
            'number' => $number,
            'zone' => $zone,
            'category' => $category,
            'category_given' => $categoryGiven,
            'drop' => $drop,
        ];
    }

    /**
     * What a category name means: a zone, a skip, a type or `main` (both no
     * category), or null for a genuine custom category.
     */
    private static function categoryKind(string $category): ?string
    {
        return self::HEADER_WORDS[self::key($category)] ?? null;
    }

    /**
     * A custom category name as the deck can store it: cut to
     * `DeckCategory::NAME_MAX`. Done here, at the source, because the review
     * page cannot edit categories — an over-long name reaching the confirm
     * request would fail validation with no way to fix it but re-pasting.
     */
    private static function categoryName(string $name): string
    {
        return rtrim(mb_substr($name, 0, DeckCategory::NAME_MAX));
    }

    /** True for a line that begins with a quantity — `4 Bolt`, `4x Bolt`. */
    private static function hasQuantity(string $line): bool
    {
        return preg_match('/^\d+\s*[xX]?\s+\S/', $line) === 1;
    }

    /** A divider: three or more of `-`, `=`, `_`, `~` or `*` and nothing else. */
    private static function isDivider(string $line): bool
    {
        return preg_match('/^[-=_~*]{3,}$/', $line) === 1;
    }

    /** Lookup key for {@see self::HEADER_WORDS}. */
    private static function key(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $label)));
    }
}
