<?php

namespace Tests\Unit\Services\Scryfall\Shadow;

use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ShadowTableRegistryTest extends TestCase
{
    #[Test]
    public function shadow_appends_the_shadow_suffix(): void
    {
        $this->assertSame('oracle_cards__shadow', ShadowTableRegistry::shadow('oracle_cards'));
        $this->assertSame('default_card_relations__shadow', ShadowTableRegistry::shadow('default_card_relations'));
    }

    #[Test]
    public function retired_appends_the_retired_suffix(): void
    {
        $this->assertSame('oracle_cards__retired', ShadowTableRegistry::retired('oracle_cards'));
        $this->assertSame('default_card_relations__retired', ShadowTableRegistry::retired('default_card_relations'));
    }

    #[Test]
    public function suffixes_do_not_collide(): void
    {
        $this->assertNotSame(ShadowTableRegistry::SHADOW_SUFFIX, ShadowTableRegistry::RETIRED_SUFFIX);
    }

    #[Test]
    public function tables_list_is_unique_and_lowercase(): void
    {
        $tables = ShadowTableRegistry::TABLES;
        $this->assertSame(array_unique($tables), $tables, 'Duplicate entries in TABLES.');
        foreach ($tables as $table) {
            $this->assertSame(strtolower($table), $table, "Table name `$table` must be lowercase.");
        }
    }

    #[Test]
    public function fk_checks_only_reference_known_target_tables(): void
    {
        $known = ShadowTableRegistry::TABLES;
        foreach (ShadowTableRegistry::FK_CHECKS as [$source, $fk, $target, $mode]) {
            $this->assertContains(
                $target,
                $known,
                "FK check ($source.$fk → $target) references a target not in TABLES."
            );
            $this->assertContains($mode, ['shadow', 'live'], "FK check mode must be 'shadow' or 'live', got `$mode`.");
        }
    }

    #[Test]
    public function fk_checks_have_a_known_user_data_set(): void
    {
        $userDataSources = [];
        foreach (ShadowTableRegistry::FK_CHECKS as [$source, , , $mode]) {
            if ($mode === 'live') {
                $userDataSources[$source] = true;
            }
        }
        // Sanity: PR 1's stated user-data FK set is deck_cards + card_stacks.
        // If anyone adds a new live source, they must intentionally update
        // this assertion — flags drift between the registry and the plan.
        $this->assertSame(
            ['deck_cards' => true, 'card_stacks' => true],
            $userDataSources,
        );
    }
}
