<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;
use App\Models\Brand;

class AdminV1BrandTest extends TestCase
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
    public function admin_v1_brands_index_returns_paginated_list(): void
    {
        Brand::factory(3)->create();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/v1/brands');

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
    public function admin_v1_brands_store_creates_brand_and_returns_201(): void
    {
        $payload = [
            'name' => 'Samsung',
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/v1/brands', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'code',
                'data' => ['id', 'name', 'slug', 'in_use', 'order', 'created_at', 'updated_at'],
            ])
            ->assertJson([
                'success' => true,
                'code'    => 201,
                'data'    => [
                    'name' => 'Samsung',
                    'slug' => 'samsung',
                ],
            ]);

        $this->assertDatabaseHas('brands', [
            'name' => 'Samsung',
            'slug' => 'samsung',
        ]);
    }

    #[Test]
    public function admin_v1_brands_store_fails_with_422_on_missing_name(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/v1/brands', [
                'slug' => 'no-name-brand',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code'    => 422,
            ])
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function admin_v1_brands_show_returns_single_brand(): void
    {
        $brand = Brand::factory()->create();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/v1/brands/{$brand->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code'    => 200,
                'data'    => [
                    'id'   => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                ],
            ]);
    }

    #[Test]
    public function admin_v1_brands_update_returns_200_with_refreshed_data(): void
    {
        $brand = Brand::factory()->create([
            'name' => 'Old Brand',
            'slug' => 'old-brand',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/admin/v1/brands/{$brand->id}", [
                'name' => 'New Brand',
                'slug' => 'new-brand',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code'    => 200,
                'data'    => [
                    'id'   => $brand->id,
                    'name' => 'New Brand',
                    'slug' => 'new-brand',
                ],
            ]);

        $this->assertDatabaseHas('brands', [
            'id'   => $brand->id,
            'name' => 'New Brand',
            'slug' => 'new-brand',
        ]);
    }

    #[Test]
    public function admin_v1_brands_destroy_returns_204(): void
    {
        $brand = Brand::factory()->create();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/admin/v1/brands/{$brand->id}");

        $response->assertStatus(204)
            ->assertNoContent();

        $this->assertDatabaseMissing('brands', [
            'id' => $brand->id,
        ]);
    }

    #[Test]
    public function admin_v1_brands_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/admin/v1/brands');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code'    => 401,
            ]);
    }
}
