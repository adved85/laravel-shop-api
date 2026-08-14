<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;
use App\Models\Category;

class AdminV1CategoryTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user for authentication
        $this->adminUser = User::factory()->create([
            "name" => "admin",
            'email' => 'admin@example.com',
            'password' => bcrypt('password123'),
            'system_role' => "admin"
        ]);


    }

    #[Test]
    public function admin_v1_categories_index_returns_paginated_list(): void
    {
        Category::factory(3)->create();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/v1/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'code',
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
                    'links'      => ['first', 'last', 'prev', 'next'],
                ],
            ])
            ->assertJson([
                'success' => true,
                'code'    => 200,
            ])
            ->assertJsonCount(3, 'data.items');
    }

    #[Test]
    public function admin_v1_categories_store_creates_category_and_returns_201(): void
    {
        $payload = [
            'name' => 'Electronics',
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/v1/categories', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'code',
                'data' => ['id', 'name', 'slug', 'parent_id', 'in_use', 'order', 'created_at', 'updated_at'],
            ])
            ->assertJson([
                'success' => true,
                'code'    => 201,
                'data'    => [
                    'name' => 'Electronics',
                    'slug' => 'electronics',
                ],
            ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
    }

    #[Test]
    public function admin_v1_categories_store_fails_with_422_on_missing_name(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/v1/categories', [
                'slug' => 'no-name-category',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code'    => 422,
            ])
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function admin_v1_categories_show_returns_single_category(): void
    {
        $category = Category::factory()->create();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/v1/categories/{$category->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code'    => 200,
                'data'    => [
                    'id'   => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ],
            ]);
    }

    #[Test]
    public function admin_v1_categories_update_returns_200_with_refreshed_data(): void
    {
        $category = Category::factory()->create([
            'name' => 'Old Name',
            'slug' => 'old-name',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/admin/v1/categories/{$category->id}", [
                'name' => 'New Name',
                'slug' => 'new-name',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code'    => 200,
                'data'    => [
                    'id'   => $category->id,
                    'name' => 'New Name',
                    'slug' => 'new-name',
                ],
            ]);

        $this->assertDatabaseHas('categories', [
            'id'   => $category->id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
    }

    #[Test]
    public function admin_v1_categories_destroy_returns_204(): void
    {
        $category = Category::factory()->create();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/admin/v1/categories/{$category->id}");

        $response->assertStatus(204)
            ->assertNoContent();

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

    #[Test]
    public function admin_v1_categories_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/admin/v1/categories');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code'    => 401,
            ]);
    }
}
