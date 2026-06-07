<?php

namespace App\Services\Scryfall;

/**
 * Process-local static bag of stats produced by sub-commands during a
 * `scryfall:update` run, surfaced by `UpdateEverything` in its
 * end-of-run summary.
 *
 * Why static: sub-commands are invoked via `$this->call('scryfall:*')`
 * within the same PHP process, so static state is the simplest way to
 * pass a counter from a sub-command's service back to the orchestrator
 * without DI plumbing or cache I/O.
 *
 * `UpdateEverything` calls `reset()` at the top of every run.
 */
class ScryfallRunStats
{
    public static int $relationsRetargeted = 0;

    public static int $relationsSkippedComponent = 0;

    public static int $relationsSkippedOrphan = 0;

    public static int $cardImagesDownloaded = 0;

    public static int $cardImagesSkipped = 0;

    public static int $cardImagesFailed = 0;

    public static int $artCropsDownloaded = 0;

    public static int $artCropsSkipped = 0;

    public static int $artCropsFailed = 0;

    public static function reset(): void
    {
        self::$relationsRetargeted = 0;
        self::$relationsSkippedComponent = 0;
        self::$relationsSkippedOrphan = 0;
        self::$cardImagesDownloaded = 0;
        self::$cardImagesSkipped = 0;
        self::$cardImagesFailed = 0;
        self::$artCropsDownloaded = 0;
        self::$artCropsSkipped = 0;
        self::$artCropsFailed = 0;
    }
}
