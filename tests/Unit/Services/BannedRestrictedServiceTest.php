<?php

namespace Tests\Unit\Services;

use App\Services\BannedRestrictedService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BannedRestrictedServiceTest extends TestCase
{
    /**
     * Each row: layout, faces (front first), expected group. The faces are
     * shaped like Scryfall's data, quirks included — split and adventure
     * halves carry no colours, devoid cards carry none despite a coloured
     * cost.
     *
     * @return array<string, array{0: string, 1: list<array{mana_cost: string|null, type_line: string, colors: string|null}>, 2: string}>
     */
    public static function colorGroupCases(): array
    {
        return [
            'mono white' => ['normal', [self::face('{W}', 'Creature — Human', 'W')], 'W'],
            'mono green' => ['normal', [self::face('{2}{G}', 'Sorcery', 'G')], 'G'],
            'two colours' => ['normal', [self::face('{U}{B}', 'Instant', 'UB')], 'multicolor'],
            'colourless artifact' => ['normal', [self::face('{1}', 'Artifact', null)], 'colorless'],
            'devoid is colourless despite its cost' => ['normal', [self::face('{2}{B}{B}', 'Creature — Eldrazi', null)], 'colorless'],
            'colour indicator without a cost' => ['normal', [self::face('', 'Sorcery', 'U')], 'U'],
            'coloured land is a land' => ['normal', [self::face('', 'Land Creature — Forest Dryad', 'G')], 'land'],
            'colourless land' => ['normal', [self::face('', 'Land', null)], 'land'],
            'artifact land' => ['normal', [self::face('', 'Artifact Land', null)], 'land'],
            'split card reads both halves\' costs' => [
                'split',
                [self::face('{1}{R}', 'Instant', null), self::face('{1}{U}', 'Instant', null)],
                'multicolor',
            ],
            'adventure with one colour stays mono' => [
                'adventure',
                [self::face('{1}{B}{B}', 'Creature — Zombie Knight', null), self::face('{1}{B}{B}', 'Instant — Adventure', null)],
                'B',
            ],
            'flip card' => [
                'flip',
                [self::face('{1}{U}', 'Legendary Creature — Moonfolk Monk', null), self::face(null, 'Legendary Enchantment', null)],
                'U',
            ],
            'transform counts only the front' => [
                'transform',
                [self::face('{U}', 'Creature — Human Wizard', 'U'), self::face('', 'Creature — Horror', 'R')],
                'U',
            ],
            'modal DFC with a land back is a spell' => [
                'modal_dfc',
                [self::face('{2}{G}', 'Sorcery', 'G'), self::face('', 'Land', null)],
                'G',
            ],
            'hybrid and phyrexian symbols' => ['normal', [self::face('{W/U}{B/P}', 'Instant', 'WUB')], 'multicolor'],
            'no faces at all' => ['normal', [], 'colorless'],
        ];
    }

    #[Test]
    #[DataProvider('colorGroupCases')]
    public function it_files_a_card_under_its_colour_group(string $layout, array $faces, string $expected): void
    {
        $this->assertSame($expected, BannedRestrictedService::colorGroup($layout, $faces));
    }

    /**
     * Each row: name, faces as [type line, rules text] (front first), expected category.
     *
     * @return array<string, array{0: string, 1: list<array{0: string, 1: string|null}>, 2: string|null}>
     */
    public static function categoryCases(): array
    {
        return [
            'conspiracy' => ['Backup Plan', [['Conspiracy', 'Draw an additional seven cards.']], 'conspiracy'],
            'ante' => ['Bronze Tablet', [['Artifact', "Remove Bronze Tablet from your deck before playing if you're not playing for ante."]], 'ante'],
            'offensive, by name' => ['Invoke Prejudice', [['Enchantment', 'Whenever an opponent casts a creature spell…']], 'offensive'],
            'name sticker' => ['_____ Goblin', [['Creature — Goblin', 'When this creature enters, you may put a name sticker on it.']], 'sticker'],
            'opens an Attraction' => ['Ticketomaton', [['Artifact Creature — Robot', 'Open an Attraction.']], 'sticker'],
            'an Attraction itself' => ['Balloon Stand', [['Artifact — Attraction', 'Visit — Create a token.']], 'sticker'],
            'sticker on the back face' => ['Front // Back', [['Creature', 'Flying'], ['Sorcery', 'Put an art sticker on target permanent.']], 'sticker'],
            'banned by name' => ['Chaos Orb', [['Artifact', 'Flip Chaos Orb onto the battlefield.']], null],
            '"ante" inside a word' => ['Antelope', [['Creature — Antelope', 'Vigilance. Its toughness is guaranteed.']], null],
            'no rules text' => ['Vanilla', [['Creature — Bear', null]], null],
        ];
    }

    #[Test]
    #[DataProvider('categoryCases')]
    public function it_files_blanket_bans_under_their_category(string $name, array $faces, ?string $expected): void
    {
        $faces = array_map(fn (array $face): array => ['type_line' => $face[0], 'oracle_text' => $face[1]], $faces);

        $this->assertSame($expected, BannedRestrictedService::category($name, $faces));
    }

    /**
     * @return array{mana_cost: string|null, type_line: string, colors: string|null}
     */
    private static function face(?string $manaCost, string $typeLine, ?string $colors): array
    {
        return ['mana_cost' => $manaCost, 'type_line' => $typeLine, 'colors' => $colors];
    }
}
