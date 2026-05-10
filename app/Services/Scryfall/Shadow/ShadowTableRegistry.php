<?php

namespace App\Services\Scryfall\Shadow;

/**
 * Single source of truth for the scryfall tables that participate in the
 * shadow-table swap and the FK relations validated before swap.
 *
 * Adding a new truncate-rebuild scryfall table requires touching this class
 * (and only this class) for the import flow to pick it up.
 */
class ShadowTableRegistry
{
    public const SHADOW_SUFFIX = '__shadow';

    public const RETIRED_SUFFIX = '__retired';

    /**
     * Every scryfall table that participates in the truncate+rebuild flow
     * and therefore needs a __shadow build target.
     *
     * Order is informational only — the orchestrator decides build order;
     * the multi-table RENAME is atomic so swap order is irrelevant.
     *
     * @var array<int, string>
     */
    public const TABLES = [
        'sets',
        'symbols',
        'artists',
        'bulk_data',
        'oracle_cards',
        'oracle_card_faces',
        'legalities',
        'default_cards',
        'default_card_relations',
        'rulings',
    ];

    /**
     * Foreign-key constraints to restore on shadow tables after build,
     * before the atomic swap. `CREATE TABLE LIKE` does NOT copy FK
     * constraints, so without this step the post-swap live tables
     * would lose every cascade-on-delete and FK validation defined
     * in the original migrations.
     *
     * Each entry is `[child_table, fk_column, parent_table, on_delete_action]`.
     * The constraint is named `<child>_<fk>_foreign_shadow` to avoid
     * collision with the live table's identically-named Laravel-default
     * constraint. References use the parent's *live* table name —
     * MariaDB's atomic multi-table RENAME rotates the FK reference to
     * the swapped-in shadow at commit time.
     *
     * Mirrors the FK definitions in:
     *   - 2026_04_05_052247_create_oracle_card_faces_table.php
     *   - 2026_04_02_072501_create_legalities_table.php
     *   - 2026_01_04_082119_create_default_cards_table.php
     *   - 2026_05_02_103505_create_default_card_relations_table.php
     *   - 2026_05_02_035333_create_rulings_table.php
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const FK_RESTORATIONS = [
        ['oracle_card_faces', 'oracle_card_id', 'oracle_cards', 'CASCADE'],
        ['legalities', 'oracle_card_id', 'oracle_cards', 'CASCADE'],
        ['default_cards', 'oracle_id', 'oracle_cards', 'CASCADE'],
        ['default_cards', 'set_id', 'sets', 'CASCADE'],
        ['default_cards', 'artist_id', 'artists', 'CASCADE'],
        ['default_card_relations', 'source_default_card_id', 'default_cards', 'CASCADE'],
        ['default_card_relations', 'related_default_card_id', 'default_cards', 'CASCADE'],
        ['rulings', 'oracle_card_id', 'oracle_cards', 'CASCADE'],
    ];

    /**
     * FK relations validated before the swap. Each entry is:
     *   [source_table, fk_column, target_table, source_mode]
     *
     * source_mode is 'shadow' for internal scryfall FKs (source is the
     * shadow build) or 'live' for user-data FKs that reference scryfall
     * data (source stays untouched; we validate that every existing user
     * row still resolves against the new scryfall dataset).
     *
     * The target is always the shadow build — orphan validation runs
     * before the swap.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: 'shadow'|'live'}>
     */
    public const FK_CHECKS = [
        // Internal scryfall FKs (shadow → shadow).
        ['default_cards', 'oracle_id', 'oracle_cards', 'shadow'],
        ['default_cards', 'set_id', 'sets', 'shadow'],
        ['default_cards', 'artist_id', 'artists', 'shadow'],
        ['oracle_card_faces', 'oracle_card_id', 'oracle_cards', 'shadow'],
        ['legalities', 'oracle_card_id', 'oracle_cards', 'shadow'],
        ['default_card_relations', 'source_default_card_id', 'default_cards', 'shadow'],
        ['default_card_relations', 'related_default_card_id', 'default_cards', 'shadow'],
        ['rulings', 'oracle_card_id', 'oracle_cards', 'shadow'],
        // User-data FKs (live → shadow). A non-zero count here means a
        // Scryfall card has gone missing under user data; the swap aborts.
        ['deck_cards', 'oracle_card_id', 'oracle_cards', 'live'],
        ['deck_cards', 'default_card_id', 'default_cards', 'live'],
        ['card_stacks', 'default_card_id', 'default_cards', 'live'],
    ];

    public static function shadow(string $table): string
    {
        return $table.self::SHADOW_SUFFIX;
    }

    public static function retired(string $table): string
    {
        return $table.self::RETIRED_SUFFIX;
    }
}
