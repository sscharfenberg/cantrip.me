<?php

namespace Tests\Unit\Formats;

use App\Enums\CardFormat;
use App\Formats\CommanderProfile;
use App\Formats\ConstructedProfile;
use App\Formats\FormatProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for {@see FormatProfile::bannedAsCommander()} —
 * Scryfall doesn't ship the "banned as commander but legal in the 99"
 * distinction structurally (it's only mentioned in the rulings), so
 * this overlay lives in the format profile.
 */
class CommanderBanlistTest extends TestCase
{
    #[Test]
    public function constructed_formats_have_no_commander_ban_overlay(): void
    {
        $profile = new ConstructedProfile(CardFormat::Modern);

        $this->assertSame([], $profile->bannedAsCommander());
    }

    #[Test]
    public function commander_proper_has_no_commander_ban_overlay(): void
    {
        $profile = new CommanderProfile(CardFormat::Commander);

        $this->assertSame([], $profile->bannedAsCommander());
    }

    #[Test]
    public function brawl_predh_pauper_commander_share_no_commander_ban_overlay(): void
    {
        $this->assertSame([], (new CommanderProfile(CardFormat::Brawl))->bannedAsCommander());
        $this->assertSame([], (new CommanderProfile(CardFormat::Predh))->bannedAsCommander());
        $this->assertSame([], (new CommanderProfile(CardFormat::PauperCommander))->bannedAsCommander());
    }

    #[Test]
    public function duel_commander_bans_breya_as_commander(): void
    {
        $profile = new CommanderProfile(CardFormat::Duel);

        $this->assertContains('Breya, Etherium Shaper', $profile->bannedAsCommander());
    }

    #[Test]
    public function to_array_exposes_banned_as_commander(): void
    {
        $duel = (new CommanderProfile(CardFormat::Duel))->toArray();
        $commander = (new CommanderProfile(CardFormat::Commander))->toArray();

        $this->assertArrayHasKey('bannedAsCommander', $duel);
        $this->assertArrayHasKey('bannedAsCommander', $commander);
        $this->assertContains('Breya, Etherium Shaper', $duel['bannedAsCommander']);
        $this->assertSame([], $commander['bannedAsCommander']);
    }
}
