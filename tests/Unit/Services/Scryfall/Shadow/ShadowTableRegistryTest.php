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
    public function tables_list_includes_translation_tables(): void
    {
        // Foreign-language search lives or dies on these two tables
        // riding through the shadow swap. If the registry forgets
        // them, the shadow build won't createLike them, the standard
        // FK validation won't run, and any post-swap insert into the
        // live translation tables would crash the very next
        // scryfall:update — surface that mistake here, not at 3am.
        $this->assertContains('oracle_card_translations', ShadowTableRegistry::TABLES);
        $this->assertContains('oracle_card_face_translations', ShadowTableRegistry::TABLES);
    }

    #[Test]
    public function fk_checks_include_translation_to_oracle_cards(): void
    {
        $expected = [
            ['oracle_card_translations', 'oracle_card_id', 'oracle_cards', 'shadow'],
            ['oracle_card_face_translations', 'oracle_card_id', 'oracle_cards', 'shadow'],
        ];
        foreach ($expected as $check) {
            $this->assertContains(
                $check,
                ShadowTableRegistry::FK_CHECKS,
                'translation FK check missing from FK_CHECKS — orphan validation would silently miss the new tables.'
            );
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
        // The user-data FK set: deck_cards + card_stacks carry card refs
        // directly; decks + containers carry a nullable display-card printing.
        // All four must be validated pre-swap, since every FK referencing a
        // swap table is captured and re-added around the RENAME — an
        // unvalidated orphan would surface as an addForeignKeys() failure
        // AFTER the swap instead of a clean abort. If anyone adds a new live
        // source, they must intentionally update this assertion — it flags
        // drift between the registry and the real schema.
        $this->assertSame(
            ['deck_cards' => true, 'card_stacks' => true, 'decks' => true, 'containers' => true],
            $userDataSources,
        );
    }
}
