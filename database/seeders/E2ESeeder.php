<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The fixture the Playwright end-to-end suite runs against.
 *
 * **Why this is not `DatabaseSeeder`.** That one chains `DeckSeeder`, which pins
 * every slot to a specific Scryfall printing by `(set code, collector number)` —
 * so it only works on a machine that has run `php artisan scryfall:update` and
 * holds the full 475 MB dataset. A browser suite cannot assume that, and should
 * not want to: the point of a fixture is that the same rows exist every time.
 *
 * **Everything here is fixed.** No factories, no `fake()`, no `inRandomOrder()`,
 * no `now()` in a value a spec might read. A browser test names the thing it
 * clicks, so a re-rolled fixture is a re-rolled set of selectors.
 *
 * IDs are literal UUIDs rather than generated ones for the same reason: a spec
 * (or a support helper reaching into the database directly) can address a row
 * without first looking it up.
 *
 * Run by `tests/e2e/support/environment.ts` after `migrate:fresh`:
 *
 * ```bash
 * php artisan db:seed --class=Database\\Seeders\\E2ESeeder --force
 * ```
 */
class E2ESeeder extends Seeder
{
    /** The account every authenticated spec signs in as. Mirrors `SEED_USER` in environment.ts. */
    public const USER_ID = '0198a1b2-0000-7000-8000-000000000001';

    public const USER_NAME = 'E2E Tester';

    public const USER_PASSWORD = 'e2e-password';

    public function run(): void
    {
        $this->seedUser();
    }

    /**
     * The signed-in account.
     *
     * Verified up front (`email_verified_at` set), because the verification
     * flow gets its own spec that mints its own unverified user — leaving this
     * one unverified would put a verification wall in front of every
     * authenticated spec instead.
     *
     * The timestamps are a fixed instant rather than `now()`: "member since" is
     * rendered on the dashboard, and a fixture whose displayed date changes with
     * the wall clock is a fixture no spec can assert against.
     */
    private function seedUser(): void
    {
        $stamp = Carbon::parse('2026-01-01 12:00:00');

        /*
         * `forceFill`, because `id`, `created_at` and `updated_at` are all
         * outside `$fillable` — `create()` would silently drop them and mint a
         * random UUID and a wall-clock timestamp instead, which is the one thing
         * this seeder exists to avoid. Casts still apply, so `password` is
         * hashed on the way in.
         */
        (new User)->forceFill([
            'id' => self::USER_ID,
            'name' => self::USER_NAME,
            'email' => 'e2e@cantrip.test',
            'locale' => 'de',
            'currency' => 'eur',
            'deck_view_default' => 'text',
            'deck_sort_default' => 'mana',
            'collection_integration_enabled' => true,
            'email_verified_at' => $stamp,
            'password' => self::USER_PASSWORD,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ])->save();
    }
}
