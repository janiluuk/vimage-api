<?php

namespace Database\Seeders;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $monthlySubscriptions = CategoryFactory::new()->create([
            'title' => 'Subscription',
        ]);

        CategoryFactory::new()->createMany([
            [
                'title' => 'Credit packs',
                'parent_id' => $monthlySubscriptions->id,
            ],
            [
                'title' => 'Standard subscription',
                'parent_id' => $monthlySubscriptions->id,
            ],
            [
                'title' => 'Premium subscription',
                'parent_id' => $monthlySubscriptions->id,
            ],
        ]);

    }
}