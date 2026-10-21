<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_upload_supported_product_images_and_choose_a_primary(): void
    {
        Storage::fake('public');
        [$tenant, $admin, $product] = $this->productForAdmin('IMAGE-A', 'image-a@example.com');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenant->uuid)
            ->post('/api/v1/products/'.$product->uuid.'/images', [
                'images' => [
                    UploadedFile::fake()->image('product.jpg'),
                    UploadedFile::fake()->image('product.png'),
                    UploadedFile::fake()->image('product.webp'),
                ],
                'primary_index' => 1,
            ]);

        $response->assertOk()->assertJsonCount(3, 'data.images');
        $this->assertDatabaseCount('product_images', 3);
        $this->assertDatabaseHas('product_images', ['product_id' => $product->id, 'sort_order' => 1, 'is_primary' => true]);

        foreach (ProductImage::query()->where('product_id', $product->id)->get() as $image) {
            Storage::disk('public')->assertExists($image->storage_path);
        }
        foreach ((array) $response->json('data.images') as $image) {
            $this->assertStringContainsString('/storage/', (string) ($image['url'] ?? ''));
        }
    }

    public function test_product_image_validation_rejects_unsupported_files_oversized_files_and_invalid_primary_indexes(): void
    {
        Storage::fake('public');
        [$tenant, $admin, $product] = $this->productForAdmin('IMAGE-B', 'image-b@example.com');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->post('/api/v1/products/'.$product->uuid.'/images', [
                'images' => [UploadedFile::fake()->create('document.pdf', 20, 'application/pdf')],
            ])->assertUnprocessable()->assertJsonValidationErrors('images.0');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->post('/api/v1/products/'.$product->uuid.'/images', [
                'images' => [UploadedFile::fake()->create('large.jpg', 5121, 'image/jpeg')],
            ])->assertUnprocessable()->assertJsonValidationErrors('images.0');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->post('/api/v1/products/'.$product->uuid.'/images', [
                'images' => [UploadedFile::fake()->image('product.jpg')],
                'primary_index' => 1,
            ])->assertUnprocessable()->assertJsonValidationErrors('primary_index');

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_product_image_upload_cannot_cross_tenant_boundaries(): void
    {
        Storage::fake('public');
        [$tenantA, $adminA] = $this->businessWithAdmin('IMAGE-C', 'image-c@example.com');
        [, , $productB] = $this->productForAdmin('IMAGE-D', 'image-d@example.com');

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->post('/api/v1/products/'.$productB->uuid.'/images', ['images' => [UploadedFile::fake()->image('private.png')]])
            ->assertNotFound();

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_gallery_upload_preserves_existing_primary_image(): void
    {
        Storage::fake('public');
        [$tenant, $admin, $product] = $this->productForAdmin('IMAGE-E', 'image-e@example.com');
        $existingPath = 'tenants/'.$tenant->uuid.'/products/'.$product->uuid.'/existing.jpg';
        Storage::disk('public')->put($existingPath, 'existing');
        ProductImage::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'storage_path' => $existingPath, 'sort_order' => 0, 'is_primary' => true]);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->post('/api/v1/products/'.$product->uuid.'/images', ['images' => [UploadedFile::fake()->image('gallery.png')]])
            ->assertOk();

        $this->assertDatabaseHas('product_images', ['storage_path' => $existingPath, 'is_primary' => true]);
        $this->assertDatabaseCount('product_images', 2);
    }

    private function productForAdmin(string $code, string $email): array
    {
        [$tenant, $admin] = $this->businessWithAdmin($code, $email);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'General '.$code, 'slug' => strtolower($code).'-general', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Product '.$code, 'slug' => strtolower($code).'-product', 'status' => 'active', 'track_inventory' => false, 'tax_exempt' => false]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $code.'-SKU', 'selling_price' => 1500, 'status' => 'active']);

        return [$tenant, $admin, $product->fresh(['images'])];
    }

    private function businessWithAdmin(string $code, string $email): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $admin];
    }
}
