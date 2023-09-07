<?php
namespace Database\Seeders;

use Database\Factories\ProductFactory;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $properties = PropertyFactory::new()->count(10)->create();

        ProductFactory::new()->count(10)
            ->hasAttached($properties, fn () => [
                'property_id' => $properties->toQuery()->inRandomOrder()->value('id'),
                'value' => fake()->unique()->sentence(rand(1, 3)),
            ])
            ->create();
    }
}