<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (Storage::directories() as $directory) {
            Storage::deleteDirectory($directory);
        }

        $this->call([
            LocationSeeder::class,
            UserRoleSeeder::class,
            UserSeeder::class,
            PermissionSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            PromoCodeSeeder::class,
            OrderSeeder::class,
            QuestionSeeder::class,
            RoleAndPermissionSeeder::class,
            WalletTypeSeeder::class,

        ]);
    }
}