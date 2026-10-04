<?php

namespace Tests\Unit\Services;

use App\Services\DeckList\DeckListParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The deck list parser against one pasted export per site, then rule by rule.
 *
 * Each fixture is asserted as a whole — every card line as
 * `[zone, quantity, name, set, number, category]` — so a rule change that
 * breaks one site's dialect fails on that site's file by name.
 */
class DeckListParserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<array{0: string, 1: int, 2: string|null, 3: string|null, 4: string|null, 5: string|null}>, 2: int}>
     */
    public static function fixtures(): array
    {
        $krenkoPrint = [
            ['command', 1, 'Krenko, Mob Boss', 'ddt', '52', null],
            ['main', 1, 'Goblin Matron', 'mmq', '189', null],
            ['main', 1, 'Sol Ring', 'c21', '263', 'Ramp'],
            ['main', 29, 'Mountain', 'unf', '239', null],
            ['side', 1, 'Pyroblast', 'ema', '142', null],
        ];

        return [
            'moxfield bulk edit' => ['moxfield-bulk-edit.txt', [
                ['main', 1, 'Arcane Signet', 'cmm', '1009', null],
                ['main', 1, 'Command Tower', 'cmm', '1027', null],
                ['main', 1, 'Lightning Bolt', '2x2', '117', 'Removal'],
                ['main', 1, 'Sol Ring', 'c21', '263', 'Ramp'],
                ['main', 29, 'Mountain', 'unf', '239', null],
            ], 0],
            'moxfield copy for mtga' => ['moxfield-mtga.txt', [
                ['command', 1, 'Krenko, Mob Boss', 'ddt', '52', null],
                ['main', 1, 'Arcane Signet', 'cmm', '1009', null],
                ['main', 1, 'Sol Ring', 'c21', '263', null],
                ['main', 29, 'Mountain', 'unf', '239', null],
            ], 0],
            'archidekt text' => ['archidekt-text.txt', [
                ['command', 1, 'Krenko, Mob Boss', 'ddt', '52', null],
                ['main', 1, 'Sol Ring', 'c21', '263', 'Ramp'],
                ['main', 1, 'Lightning Bolt', '2x2', '117', 'Removal'],
                ['main', 29, 'Mountain', 'unf', '239', null],
                ['side', 1, 'Pyroblast', 'ema', '142', null],
            ], 2],
            'draftsim' => ['draftsim.txt', [
                ['main', 1, 'Krenko, Mob Boss', null, null, null],
                ['main', 1, 'Goblin Matron', null, null, null],
                ['main', 1, 'Lightning Bolt', null, null, null],
                ['main', 29, 'Mountain', null, null, null],
            ], 0],
            'mtg arena' => ['mtga.txt', [
                ['command', 1, 'Krenko, Mob Boss', 'ddt', '52', null],
                ['main', 1, 'Sol Ring', 'c21', '263', null],
                ['main', 29, 'Mountain', 'unf', '239', null],
                ['side', 1, 'Pyroblast', 'ema', '142', null],
            ], 0],
            'mtgo' => ['mtgo.txt', [
                ['main', 4, 'Lightning Bolt', null, null, null],
                ['main', 20, 'Mountain', null, null, null],
                ['main', 4, 'Goblin Guide', null, null, null],
                ['side', 2, 'Pyroblast', null, null, null],
                ['side', 1, 'Smash to Smithereens', null, null, null],
            ], 0],
            'cantrip print, en' => ['cantrip-print.txt', $krenkoPrint, 0],
            'cantrip print, de' => ['cantrip-print-de.txt', $krenkoPrint, 0],
        ];
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string|null, 3: string|null, 4: string|null, 5: string|null}>  $expected
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function it_reads_each_sites_export(string $file, array $expected, int $dropped): void
    {
        $parsed = DeckListParser::parse((string) file_get_contents(__DIR__.'/../../Fixtures/deck-lists/'.$file));

        $this->assertSame($expected, $this->summarize($parsed['lines']));
        $this->assertSame($dropped, $parsed['dropped']);
    }

    #[Test]
    public function the_mtgo_sideboard_is_a_flagged_guess(): void
    {
        $parsed = DeckListParser::parse((string) file_get_contents(__DIR__.'/../../Fixtures/deck-lists/mtgo.txt'));

        $this->assertSame([false, true], array_column($parsed['sections'], 'zone_guessed'));
        $this->assertSame(['main', 'side'], array_column($parsed['sections'], 'zone'));
        $this->assertTrue($parsed['lines'][3]['zone_guessed']);
        $this->assertFalse($parsed['lines'][0]['zone_guessed']);
    }

    #[Test]
    public function three_headerless_blocks_are_not_guessed_at(): void
    {
        $parsed = DeckListParser::parse("4 Lightning Bolt\n\n4 Goblin Guide\n\n2 Pyroblast");

        $this->assertSame(['main', 'main', 'main'], array_column($parsed['lines'], 'zone'));
        $this->assertSame([false, false, false], array_column($parsed['sections'], 'zone_guessed'));
    }

    #[Test]
    public function a_blank_line_under_headers_is_not_a_sideboard(): void
    {
        $parsed = DeckListParser::parse("Deck\n4 Lightning Bolt\n\n4 Goblin Guide");

        $this->assertSame(['main', 'main'], array_column($parsed['lines'], 'zone'));
    }

    #[Test]
    public function line_numbers_and_raw_text_point_back_at_the_paste(): void
    {
        $parsed = DeckListParser::parse("Deck\n\n  4x Lightning Bolt  ");

        $this->assertSame(3, $parsed['lines'][0]['line']);
        $this->assertSame('4x Lightning Bolt', $parsed['lines'][0]['raw']);
    }

    /**
     * @return array<string, array{0: string, 1: array{0: string, 1: int, 2: string|null, 3: string|null, 4: string|null, 5: string|null}}>
     */
    public static function cardLines(): array
    {
        return [
            'no quantity' => ['Sol Ring', ['main', 1, 'Sol Ring', null, null, null]],
            'x suffix' => ['4x Lightning Bolt', ['main', 4, 'Lightning Bolt', null, null, null]],
            'spaced x' => ['4 x Lightning Bolt', ['main', 4, 'Lightning Bolt', null, null, null]],
            'set only' => ['1 Sol Ring (C21)', ['main', 1, 'Sol Ring', 'c21', null, null]],
            'lettered collector number' => ['1 Delver of Secrets (ISD) 51a', ['main', 1, 'Delver of Secrets', 'isd', '51a', null]],
            'the list number' => ['1 Sol Ring (PLST) C21-263', ['main', 1, 'Sol Ring', 'plst', 'C21-263', null]],
            'star number' => ['1 Sol Ring (PRM) ★12', ['main', 1, 'Sol Ring', 'prm', '★12', null]],
            'split card name kept whole' => ['1 Fire // Ice (MH2) 290', ['main', 1, 'Fire // Ice', 'mh2', '290', null]],
            'comma in the name' => ['1 Krenko, Mob Boss', ['main', 1, 'Krenko, Mob Boss', null, null, null]],
            'etched marker' => ['1 Sol Ring (C21) 263 *E*', ['main', 1, 'Sol Ring', 'c21', '263', null]],
            'mtgo sideboard prefix' => ['SB: 2 Pyroblast', ['side', 2, 'Pyroblast', null, null, null]],
            'first archidekt category wins' => ['1 Sol Ring [Ramp,Artifact]', ['main', 1, 'Sol Ring', null, null, 'Ramp']],
            'archidekt type category is no category' => ['1 Sol Ring [Artifact,Ramp]', ['main', 1, 'Sol Ring', null, null, null]],
            'archidekt companion category' => ['1 Lurrus of the Dream-Den [Companion]', ['companion', 1, 'Lurrus of the Dream-Den', null, null, null]],
            'moxfield global tag' => ['1 Sol Ring #!Staple', ['main', 1, 'Sol Ring', null, null, 'Staple']],
            'moxfield tag with spaces' => ['1 Brainstorm #Card Draw #Cheap', ['main', 1, 'Brainstorm', null, null, 'Card Draw']],
            'zero quantity is unparseable' => ['0 Sol Ring', ['main', 0, null, null, null, null]],
        ];
    }

    /**
     * @param  array{0: string, 1: int, 2: string|null, 3: string|null, 4: string|null, 5: string|null}  $expected
     */
    #[Test]
    #[DataProvider('cardLines')]
    public function it_reads_a_card_line(string $line, array $expected): void
    {
        $parsed = DeckListParser::parse($line);

        $this->assertSame([$expected], $this->summarize($parsed['lines']));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function headers(): array
    {
        return [
            'known word' => ["Sideboard\n1 Pyroblast", 'side', null],
            'known word, upper case, colon' => ["SIDEBOARD:\n1 Pyroblast", 'side', null],
            'slashes' => ["// Sideboard\n1 Pyroblast", 'side', null],
            'commander' => ["Commander\n1 Krenko, Mob Boss", 'command', null],
            'companion' => ["Companion\n1 Lurrus of the Dream-Den", 'companion', null],
            'type headline' => ["Creatures (12)\n1 Goblin Guide", 'main', null],
            'german type headline' => ["Länder\n1 Mountain", 'main', null],
            'custom with count' => ["Ramp (3)\n1 Sol Ring", 'main', 'Ramp'],
            'custom with colon' => ["Ramp:\n1 Sol Ring", 'main', 'Ramp'],
            'custom with slashes and count' => ["// Ramp (3)\n1 Sol Ring", 'main', 'Ramp'],
            'custom with divider below' => ["Ramp\n-----\n1 Sol Ring", 'main', 'Ramp'],
        ];
    }

    #[Test]
    #[DataProvider('headers')]
    public function a_marked_header_sets_zone_and_category(string $text, string $zone, ?string $category): void
    {
        $line = DeckListParser::parse($text)['lines'][0];

        $this->assertSame($zone, $line['zone']);
        $this->assertSame($category, $line['category']);
    }

    #[Test]
    public function an_unmarked_line_is_a_card_not_a_category(): void
    {
        // The reason headers need a mark: a bare name must never vanish into
        // a category — at worst it fails to resolve and the user sees it.
        $parsed = DeckListParser::parse("Ramp\n1 Sol Ring");

        $this->assertSame([
            ['main', 1, 'Ramp', null, null, null],
            ['main', 1, 'Sol Ring', null, null, null],
        ], $this->summarize($parsed['lines']));
    }

    #[Test]
    public function an_inline_category_beats_the_header(): void
    {
        $parsed = DeckListParser::parse("Ramp:\n1 Sol Ring [Mana Rocks]\n1 Arcane Signet [Artifact]\n1 Cultivate");

        $this->assertSame(['Mana Rocks', null, 'Ramp'], array_column($parsed['lines'], 'category'));
    }

    #[Test]
    public function a_type_headline_ends_a_custom_category(): void
    {
        $parsed = DeckListParser::parse("Ramp:\n1 Sol Ring\nCreatures:\n1 Goblin Guide");

        $this->assertSame(['Ramp', null], array_column($parsed['lines'], 'category'));
    }

    #[Test]
    public function a_type_headline_returns_from_the_command_zone_to_main(): void
    {
        $parsed = DeckListParser::parse("Commander\n1 Krenko, Mob Boss\nCreatures\n1 Goblin Guide");

        $this->assertSame(['command', 'main'], array_column($parsed['lines'], 'zone'));
    }

    #[Test]
    public function command_zone_and_companion_lines_carry_no_category(): void
    {
        $parsed = DeckListParser::parse("1 Krenko, Mob Boss [Commander]\n1 Lurrus of the Dream-Den [Companion]\nSideboard\n1 Pyroblast [Hate]");

        $this->assertSame([null, null, 'Hate'], array_column($parsed['lines'], 'category'));
    }

    #[Test]
    public function the_maybeboard_is_dropped_and_counted(): void
    {
        $parsed = DeckListParser::parse("1 Sol Ring\nMaybeboard\n1 Goblin Guide\n1 Goblin Lackey\nSideboard\n1 Pyroblast");

        $this->assertSame(['Sol Ring', 'Pyroblast'], array_column($parsed['lines'], 'name'));
        $this->assertSame(2, $parsed['dropped']);
    }

    #[Test]
    public function an_ignored_section_is_skipped_without_counting(): void
    {
        $parsed = DeckListParser::parse("Tokens\n1 Goblin\n\nDeck\n1 Sol Ring");

        $this->assertSame(['Sol Ring'], array_column($parsed['lines'], 'name'));
        $this->assertSame(0, $parsed['dropped']);
    }

    #[Test]
    public function noise_is_skipped(): void
    {
        $parsed = DeckListParser::parse("# my list\n// built for FNM\n\n1 Sol Ring\n=====");

        $this->assertSame([['main', 1, 'Sol Ring', null, null, null]], $this->summarize($parsed['lines']));
        $this->assertSame([null], array_column($parsed['sections'], 'label'));
    }

    #[Test]
    public function a_title_block_needs_a_major_divider(): void
    {
        // Without `====` dividers the opening line is an ordinary line — it
        // is shown, unresolved, rather than silently eaten.
        $parsed = DeckListParser::parse("Krenko Goblins\n1 Sol Ring");

        $this->assertSame(['Krenko Goblins', 'Sol Ring'], array_column($parsed['lines'], 'name'));
    }

    #[Test]
    public function every_header_opens_a_section(): void
    {
        $parsed = DeckListParser::parse("Commander\n1 Krenko, Mob Boss\n\nDeck\n1 Sol Ring\nSideboard\n1 Pyroblast");

        $this->assertSame(['Commander', 'Deck', 'Sideboard'], array_column($parsed['sections'], 'label'));
        $this->assertSame([0, 1, 2], array_column($parsed['lines'], 'section'));
    }

    #[Test]
    public function windows_line_endings_and_a_bom_are_tolerated(): void
    {
        $parsed = DeckListParser::parse("\xEF\xBB\xBFDeck\r\n4 Lightning Bolt\r\n");

        $this->assertSame([['main', 4, 'Lightning Bolt', null, null, null]], $this->summarize($parsed['lines']));
    }

    /**
     * @param  list<array{zone: string, quantity: int, name: string|null, set: string|null, number: string|null, category: string|null}>  $lines
     * @return list<array{0: string, 1: int, 2: string|null, 3: string|null, 4: string|null, 5: string|null}>
     */
    private function summarize(array $lines): array
    {
        return array_map(fn (array $line): array => [
            $line['zone'], $line['quantity'], $line['name'], $line['set'], $line['number'], $line['category'],
        ], $lines);
    }
}
