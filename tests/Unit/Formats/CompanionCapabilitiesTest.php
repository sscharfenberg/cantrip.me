<?php

namespace Tests\Unit\Formats;

use App\Enums\CardFormat;
use App\Formats\CommanderProfile;
use App\Formats\ConstructedProfile;
use App\Formats\FormatProfile;
use App\Formats\OathbreakerProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the two companion-mechanic capabilities added to
 * {@see FormatProfile}: `allowsCompanion()` and
 * `bannedAsCompanion()`.
 *
 * These tests only instantiate the profile classes — no DB needed — so they
 * run under SQLite locally.
 */
class CompanionCapabilitiesTest extends TestCase
{
    #[Test]
    public function constructed_formats_allow_companion_with_empty_ban_list(): void
    {
        $profile = new ConstructedProfile(CardFormat::Modern);

        $this->assertTrue($profile->allowsCompanion());
        $this->assertSame([], $profile->bannedAsCompanion());
    }

    #[Test]
    public function commander_bans_lutri_as_companion(): void
    {
        $profile = new CommanderProfile(CardFormat::Commander);

        $this->assertTrue($profile->allowsCompanion());
        $this->assertContains('Lutri, the Spellchaser', $profile->bannedAsCompanion());
    }

    #[Test]
    public function oathbreaker_disallows_companion_entirely(): void
    {
        $profile = new OathbreakerProfile(CardFormat::Oathbreaker);

        $this->assertFalse($profile->allowsCompanion());
        $this->assertSame([], $profile->bannedAsCompanion());
    }

    #[Test]
    public function to_array_exposes_companion_capabilities(): void
    {
        $commander = (new CommanderProfile(CardFormat::Commander))->toArray();

        $this->assertArrayHasKey('allowsCompanion', $commander);
        $this->assertArrayHasKey('bannedAsCompanion', $commander);
        $this->assertTrue($commander['allowsCompanion']);
        $this->assertContains('Lutri, the Spellchaser', $commander['bannedAsCompanion']);
    }
}
