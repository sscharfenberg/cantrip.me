<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $start = hrtime(true);
        $this->call([
            UserSeeder::class,
            ContainerSeeder::class,
            DeckSeeder::class,
        ]);
        $execution = hrtime(true) - $start;
        echo "Database seeding took \033[92m".$execution / 1e+9."\033[39m seconds.\n\n";
    }
}
