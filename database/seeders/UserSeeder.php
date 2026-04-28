<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed users with role mapping:
     * 0 = admin, 1 = department, 2 = faculty
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'ryan@example.com'],
            [
                'name' => 'Ryan',
                'password' => Hash::make('@password123'),
                'role' => 0,
            ]
        );

        User::updateOrCreate(
            ['email' => 'department@example.com'],
            [
                'name' => 'Department User',
                'password' => Hash::make('@password123'),
                'role' => 1,
            ]
        );

        User::updateOrCreate(
            ['email' => 'faculty@example.com'],
            [
                'name' => 'Faculty User',
                'password' => Hash::make('@password123'),
                'role' => 2,
            ]
        );
    }
}
