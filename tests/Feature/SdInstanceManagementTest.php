<?php

namespace Tests\Feature;

use App\Models\SdInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SdInstanceManagementTest extends TestCase
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

    public function test_index_returns_all_sd_instances(): void
    {
        SdInstance::factory()->count(3)->create();

        $response = $this->getJson('/api/administration/sd-instances');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    public function test_store_creates_new_sd_instance(): void
    {
        $data = [
            'name' => 'Test SD Instance',
            'url' => 'http://192.168.1.100:7860',
            'type' => 'stable_diffusion_forge',
            'enabled' => true,
        ];

        $response = $this->postJson('/api/administration/sd-instances', $data);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'name' => 'Test SD Instance',
            'url' => 'http://192.168.1.100:7860',
            'type' => 'stable_diffusion_forge',
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('sd_instances', $data);
    }



    public function test_show_returns_single_sd_instance(): void
    {
        $instance = SdInstance::factory()->create([
            'name' => 'Test Instance',
            'url' => 'http://test.local:7860',
        ]);

        $response = $this->getJson("/api/administration/sd-instances/{$instance->id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $instance->id,
            'name' => 'Test Instance',
            'url' => 'http://test.local:7860',
        ]);
    }

    public function test_show_returns_404_for_nonexistent_instance(): void
    {
        $response = $this->getJson('/api/administration/sd-instances/999');

        $response->assertNotFound();
    }

    public function test_update_modifies_sd_instance(): void
    {
        $instance = SdInstance::factory()->create([
            'name' => 'Old Name',
            'url' => 'http://old.local:7860',
            'type' => 'stable_diffusion_forge',
        ]);

        $data = [
            'name' => 'New Name',
            'url' => 'http://new.local:7860',
            'type' => 'comfyui',
        ];

        $response = $this->putJson("/api/administration/sd-instances/{$instance->id}", $data);

        $response->assertOk();
        $response->assertJsonFragment($data);

        $instance->refresh();
        $this->assertEquals('New Name', $instance->name);
        $this->assertEquals('http://new.local:7860', $instance->url);
        $this->assertEquals('comfyui', $instance->type);
    }

    public function test_update_allows_partial_updates(): void
    {
        $instance = SdInstance::factory()->create([
            'name' => 'Original Name',
            'enabled' => false,
        ]);

        $response = $this->patchJson("/api/administration/sd-instances/{$instance->id}", [
            'enabled' => true,
        ]);

        $response->assertOk();

        $instance->refresh();
        $this->assertEquals('Original Name', $instance->name);
        $this->assertTrue($instance->enabled);
    }

    public function test_destroy_deletes_sd_instance(): void
    {
        $instance = SdInstance::factory()->create();

        $response = $this->deleteJson("/api/administration/sd-instances/{$instance->id}");

        $response->assertOk();
        $response->assertJson(['message' => 'SD instance deleted successfully']);

        $this->assertDatabaseMissing('sd_instances', ['id' => $instance->id]);
    }

    public function test_toggle_changes_enabled_status_from_true_to_false(): void
    {
        $instance = SdInstance::factory()->create(['enabled' => true]);

        $response = $this->patchJson("/api/administration/sd-instances/{$instance->id}/toggle");

        $response->assertOk();
        $response->assertJsonFragment(['enabled' => false]);

        $instance->refresh();
        $this->assertFalse($instance->enabled);
    }

    public function test_toggle_changes_enabled_status_from_false_to_true(): void
    {
        $instance = SdInstance::factory()->create(['enabled' => false]);

        $response = $this->patchJson("/api/administration/sd-instances/{$instance->id}/toggle");

        $response->assertOk();
        $response->assertJsonFragment(['enabled' => true]);

        $instance->refresh();
        $this->assertTrue($instance->enabled);
    }

    public function test_multiple_toggles_alternate_status(): void
    {
        $instance = SdInstance::factory()->create(['enabled' => true]);

        $this->patchJson("/api/administration/sd-instances/{$instance->id}/toggle");
        $instance->refresh();
        $this->assertFalse($instance->enabled);

        $this->patchJson("/api/administration/sd-instances/{$instance->id}/toggle");
        $instance->refresh();
        $this->assertTrue($instance->enabled);
    }
}
