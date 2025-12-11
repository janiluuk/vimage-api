<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Videojob;
use App\Models\Item;
use App\Models\Order;
use App\Models\FinanceOperationsHistory;
use App\Models\Message;
use App\Models\Chat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDataManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\AuthorizationChecker::class,
            \App\Http\Middleware\IsAdministratorChecker::class,
        ]);
    }

    public function test_get_all_users_includes_data_stats(): void
    {
        $user = User::factory()->create();
        
        // Create some related data
        Product::factory()->count(2)->create(['user_id' => $user->id]);
        Item::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/administration/users');

        $response->assertOk();
        $response->assertJsonStructure([
            '*' => [
                'id',
                'email',
                'login',
                'data_stats' => [
                    'products_count',
                    'video_jobs_count',
                    'items_count',
                    'messages_count',
                    'chats_count',
                    'orders_count',
                    'finance_operations_count',
                    'support_requests_count',
                    'media_count',
                ],
            ],
        ]);

        // Verify the counts are correct for the created user
        $userData = collect($response->json())->firstWhere('id', $user->id);
        $this->assertEquals(2, $userData['data_stats']['products_count']);
        $this->assertEquals(3, $userData['data_stats']['items_count']);
    }

    public function test_get_user_data_stats_returns_correct_counts(): void
    {
        $user = User::factory()->create();
        
        // Create related data
        Product::factory()->count(5)->create(['user_id' => $user->id]);
        Item::factory()->count(3)->create(['user_id' => $user->id]);
        Order::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->getJson("/api/administration/users/{$user->id}/data-stats");

        $response->assertOk();
        $response->assertJson([
            'products_count' => 5,
            'items_count' => 3,
            'orders_count' => 2,
            'video_jobs_count' => 0,
            'messages_count' => 0,
            'chats_count' => 0,
            'finance_operations_count' => 0,
            'support_requests_count' => 0,
            'media_count' => 0,
        ]);
    }

    public function test_get_user_data_stats_returns_404_for_nonexistent_user(): void
    {
        $response = $this->getJson('/api/administration/users/999/data-stats');

        $response->assertNotFound();
    }

    public function test_purge_user_data_deletes_all_related_data(): void
    {
        $user = User::factory()->create();
        
        // Create related data
        $products = Product::factory()->count(3)->create(['user_id' => $user->id]);
        $items = Item::factory()->count(2)->create(['user_id' => $user->id]);
        $orders = Order::factory()->count(1)->create(['user_id' => $user->id]);

        $response = $this->deleteJson('/api/administration/users/purge-data', [
            'id' => $user->id,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'msg',
            'purged_counts' => [
                'products',
                'video_jobs',
                'items',
                'messages',
                'chats',
                'orders',
                'finance_operations',
                'support_requests',
                'media',
            ],
        ]);

        // Verify the counts returned
        $this->assertEquals(3, $response->json('purged_counts.products'));
        $this->assertEquals(2, $response->json('purged_counts.items'));
        $this->assertEquals(1, $response->json('purged_counts.orders'));

        // Verify data is actually deleted
        $this->assertDatabaseMissing('products', ['id' => $products[0]->id]);
        $this->assertDatabaseMissing('items', ['id' => $items[0]->id]);
        $this->assertDatabaseMissing('orders', ['id' => $orders[0]->id]);

        // Verify user still exists
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_purge_user_data_returns_404_for_nonexistent_user(): void
    {
        $response = $this->deleteJson('/api/administration/users/purge-data', [
            'id' => 999,
        ]);

        $response->assertNotFound();
    }
}
