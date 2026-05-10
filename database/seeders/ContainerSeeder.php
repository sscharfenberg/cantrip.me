<?php

namespace Database\Seeders;

use App\Enums\CardLanguage;
use App\Enums\ContainerType;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\DefaultCard;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContainerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Requires default_cards to be populated first (via `php artisan scryfall:update`).
     */
    public function run(): void
    {
        $user = User::first();

        // Wipe existing card stacks and containers so the seeder is idempotent.
        CardStack::where('user_id', $user->id)->delete();
        Container::where('user_id', $user->id)->delete();

        $cardIds = DefaultCard::inRandomOrder()->limit(10)->pluck('id');

        $containers = [
            ['name' => 'Legacy Staples', 'type' => ContainerType::Binder, 'description' => 'Force of Will, Wasteland, etc.'],
            ['name' => 'Modern Deck: Murktide', 'type' => ContainerType::Deckbox, 'description' => 'UR Murktide Regent'],
            ['name' => 'Atraxa', 'type' => ContainerType::Deckbox, 'description' => 'Atraxa, Praetors\' Voice superfriends'],
            ['name' => 'Kithkin', 'type' => ContainerType::Deckbox, 'description' => 'Brigid, Clachan\'s Heart'],
            ['name' => 'Trade Binder', 'type' => ContainerType::Binder, 'description' => 'Cards available for trade'],
            ['name' => 'Cube 360', 'type' => ContainerType::Binder, 'description' => 'Powered vintage cube'],
            ['name' => 'MH3 Display', 'type' => ContainerType::Display, 'description' => 'Assorted Modern Horizons 3 cards'],
            ['name' => 'Toploaders', 'type' => ContainerType::Toploader, 'description' => 'High-value singles in toploaders'],
            ['name' => 'Holiday Gift Tin', 'type' => ContainerType::Tin, 'description' => '2024 holiday promo tin'],
            ['name' => 'Misc Tokens', 'type' => ContainerType::Other, 'description' => 'Token and emblem collection', 'custom_type' => 'Token Box'],
        ];

        $createdContainers = [];
        foreach ($containers as $index => $data) {
            $createdContainers[] = Container::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'type' => $data['type'],
                'description' => $data['description'],
                'custom_type' => $data['custom_type'] ?? null,
                'default_card_id' => $cardIds[$index] ?? null,
                'sort_order' => $index + 1,
            ]);
        }

        // Distribute 60 random cards across the containers. Pluck the
        // finishes bitmask alongside the id so we can pick a finish that
        // the card actually supports — the table column defaults to
        // Nonfoil (1), which is invalid for ~20% of printings (foil-only
        // and etched-only cards) and would render the cardstack edit
        // form unusable for those rows.
        $randomCards = DefaultCard::inRandomOrder()->limit(200)->get(['id', 'finishes']);
        $languages = [CardLanguage::En, CardLanguage::De];

        foreach ($randomCards as $card) {
            $container = $createdContainers[array_rand($createdContainers)];
            $availableFinishes = Finish::labelsFromMask($card->finishes);
            $finish = Finish::fromLabel($availableFinishes[array_rand($availableFinishes)]);

            CardStack::create([
                'user_id' => $user->id,
                'default_card_id' => $card->id,
                'container_id' => $container->id,
                'amount' => random_int(1, 4),
                'language' => $languages[array_rand($languages)],
                'finish' => $finish,
            ]);
        }
    }
}
