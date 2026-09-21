<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything in DatabaseSeeder plus sample products and demo stock.
 *
 * Intended for local development and demonstrations only.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DatabaseSeeder::class,
            DemoCatalogueSeeder::class,
        ]);
    }
}
