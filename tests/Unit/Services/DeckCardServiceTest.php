<?php

namespace Tests\Unit\Services;

use App\Services\DeckCardService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pure mana-cost parser inside DeckCardService.
 *
 * The DB-touching `recalculateColors` entry point is exercised end-to-end
 * via the DeckCardController feature tests (which run on staging with
 * MariaDB); here we only pin the token-parsing logic that matters.
 */
class DeckCardServiceTest extends TestCase
{
    #[Test]
    public function extracts_plain_colored_pips(): void
    {
        $this->assertSame('R', DeckCardService::extractColorsFromManaCosts(['{R}']));
        $this->assertSame('WUBRG', DeckCardService::extractColorsFromManaCosts([
            '{W}', '{U}', '{B}', '{R}', '{G}',
        ]));
    }

    #[Test]
    public function returns_empty_for_colorless_and_generic_costs(): void
    {
        $this->assertSame('', DeckCardService::extractColorsFromManaCosts([
            '{1}', '{C}', '{X}', '{0}', '{S}', '',
        ]));
    }

    #[Test]
    public function hybrid_pip_contributes_both_colors(): void
    {
        // Kitchen Finks: {1}{G/W}{G/W}
        $this->assertSame('WG', DeckCardService::extractColorsFromManaCosts(['{1}{G/W}{G/W}']));
    }

    #[Test]
    public function phyrexian_pip_contributes_its_colored_component(): void
    {
        // Gitaxian Probe: {U/P}
        $this->assertSame('U', DeckCardService::extractColorsFromManaCosts(['{U/P}']));
    }

    #[Test]
    public function monocolored_hybrid_pip_contributes_its_colored_component(): void
    {
        // Reaper King-style {2/W}{2/U}
        $this->assertSame('WU', DeckCardService::extractColorsFromManaCosts(['{2/W}{2/U}']));
    }

    #[Test]
    public function deduplicates_and_sorts_in_canonical_wubrg_order(): void
    {
        $this->assertSame('WUBRG', DeckCardService::extractColorsFromManaCosts([
            '{R}', '{G}{G}', '{B}', '{U}', '{W}', '{R}',
        ]));
    }

    #[Test]
    public function ignores_null_and_empty_strings(): void
    {
        $this->assertSame('R', DeckCardService::extractColorsFromManaCosts(['', '{R}', '']));
    }

    #[Test]
    public function split_card_contributes_colors_from_both_halves(): void
    {
        // Fire // Ice — passed as two face rows.
        $this->assertSame('UR', DeckCardService::extractColorsFromManaCosts([
            '{1}{R}',
            '{1}{U}',
        ]));
    }
}
