<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Keep this for all environments (including deploy/production).
        $this->call(UserSeeder::class);

        // Temporarily include demo data for deploy.
        // if (app()->environment(['local', 'development', 'testing'])) {
            $this->call(DemoDataSeeder::class);
        // }
    }
}
