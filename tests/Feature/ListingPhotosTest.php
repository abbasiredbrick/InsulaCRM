<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyMedia;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingPhotosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public function test_upload_photos_persists_media_rows_with_tenant_id(): void
    {
        $property = $this->createProperty();

        $response = $this->post(route('inventory.photos.upload', $property), [
            'photos' => [\Illuminate\Http\UploadedFile::fake()->image('unit-front.png')],
        ]);

        $response->assertRedirect(route('inventory.show', $property));

        $this->assertDatabaseHas('property_media', [
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'type' => 'photo',
            'original_name' => 'unit-front.png',
            'uploaded_by' => auth()->id(),
        ]);
    }

    public function test_upload_photos_requires_valid_image_files(): void
    {
        $property = $this->createProperty();

        $this->post(route('inventory.photos.upload', $property), [
            'photos' => [\Illuminate\Http\UploadedFile::fake()->create('notes.txt', 10)],
        ])->assertSessionHasErrors('photos.0');

        $this->assertDatabaseCount('property_media', 0);
    }

    public function test_set_primary_photo_flags_one_and_unflags_others(): void
    {
        $property = $this->createProperty();
        $first = $this->makePhoto($property, 1);
        $second = $this->makePhoto($property, 2);
        $third = $this->makePhoto($property, 3);

        $this->postJson(route('inventory.photos.primary', [$property, $second]))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertFalse($third->fresh()->is_primary);

        $this->postJson(route('inventory.photos.primary', [$property, $third]))->assertOk();

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertFalse($second->fresh()->is_primary);
        $this->assertTrue($third->fresh()->is_primary);
    }

    public function test_reorder_photos_persists_new_sort_order_and_first_becomes_main(): void
    {
        $property = $this->createProperty();
        $a = $this->makePhoto($property, 1);
        $b = $this->makePhoto($property, 2);
        $c = $this->makePhoto($property, 3);

        $this->postJson(route('inventory.photos.reorder', $property), [
            'ids' => [$c->id, $a->id, $b->id],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(3, $b->fresh()->sort_order);
        $this->assertSame(1, $c->fresh()->sort_order);

        $this->assertTrue($c->fresh()->is_primary);
        $this->assertFalse($a->fresh()->is_primary);
        $this->assertFalse($b->fresh()->is_primary);
    }

    public function test_first_uploaded_photo_becomes_main(): void
    {
        $property = $this->createProperty();

        $this->post(route('inventory.photos.upload', $property), [
            'photos' => [
                \Illuminate\Http\UploadedFile::fake()->image('front.png'),
                \Illuminate\Http\UploadedFile::fake()->image('rear.png'),
            ],
        ])->assertRedirect();

        $photos = $property->media()->where('type', 'photo')->orderBy('id')->get();
        $this->assertCount(2, $photos);
        $this->assertTrue($photos->first()->is_primary);
        $this->assertFalse($photos->last()->is_primary);

        $this->post(route('inventory.photos.upload', $property), [
            'photos' => [\Illuminate\Http\UploadedFile::fake()->image('extra.png')],
        ])->assertRedirect();

        $photos = $property->media()->where('type', 'photo')->orderBy('id')->get();
        $this->assertTrue($photos->where('original_name', 'front.png')->first()->is_primary);
    }

    public function test_set_primary_moves_photo_to_first_position(): void
    {
        $property = $this->createProperty();
        $first = $this->makePhoto($property, 1);
        $second = $this->makePhoto($property, 2);

        $this->postJson(route('inventory.photos.primary', [$property, $second]))->assertOk();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_delete_photo_promotes_first_remaining_photo_as_main(): void
    {
        $property = $this->createProperty();
        $main = $this->makePhoto($property, 1);
        $second = $this->makePhoto($property, 2);
        $main->update(['is_primary' => true]);

        $this->delete(route('inventory.photos.delete', [$property, $main]))
            ->assertRedirect(route('inventory.show', $property));

        $this->assertNull($main->fresh());
        $this->assertSame(2, $second->fresh()->sort_order);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_delete_photo_removes_local_file(): void
    {
        $property = $this->createProperty();
        $photo = PropertyMedia::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'type' => 'photo',
            'path' => 'photos/unit.jpg',
            'sort_order' => 1,
            'is_primary' => true,
        ]);

        \Illuminate\Support\Facades\Storage::disk('public')->put('photos/unit.jpg', 'data');

        $this->delete(route('inventory.photos.delete', [$property, $photo]))->assertRedirect();

        $this->assertDatabaseMissing('property_media', ['id' => $photo->id]);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing('photos/unit.jpg');
    }

    public function test_photo_mutations_are_scoped_to_own_tenant(): void
    {
        // Acting tenant is the setUp tenant (id X). Spin up a SECOND tenant's
        // property + photos so they belong to a different tenant entirely.
        $other = $this->createTenantWithAdmin([
            'name' => 'Other Company',
            'slug' => 'other-'.Str::lower(Str::random(8)),
            'email' => Str::random(8).'@test.com',
        ]);
        $property = $this->createProperty(); // now created under tenant Y
        $media = $this->makePhoto($property, 1);

        $this->assertNotSame($this->tenant->id, auth()->user()->tenant_id);

        $this->postJson(route('inventory.photos.primary', [$property, $media]))->assertNotFound();
        $this->postJson(route('inventory.photos.reorder', $property), ['ids' => [$media->id]])
            ->assertNotFound();

        $this->assertSame(1, $media->fresh()->sort_order);
        $this->assertFalse((bool) $media->fresh()->is_primary);
    }

    public function test_drive_external_urls_render_as_view_images(): void
    {
        $property = $this->createProperty();
        $media = $this->makePhoto($property, 1);
        $media->update(['external_url' => 'https://drive.google.com/file/d/ABC123/view?usp=drive_link']);

        $this->assertSame('https://drive.google.com/thumbnail?id=ABC123&sz=w1600', $media->url());
    }

    public function test_drive_download_ur_ls_also_render_as_view_images(): void
    {
        $property = $this->createProperty();
        $media = $this->makePhoto($property, 1);
        $media->update(['external_url' => 'https://drive.google.com/uc?id=XYZ123&export=download']);

        $this->assertSame('https://drive.google.com/thumbnail?id=XYZ123&sz=w1600', $media->url());
    }

    public function test_non_drive_external_urls_are_kept_as_is(): void
    {
        $property = $this->createProperty();
        $media = $this->makePhoto($property, 1);
        $media->update(['external_url' => 'https://images.example.test/pic.jpg']);

        $this->assertSame('https://images.example.test/pic.jpg', $media->url());
    }

    protected function makePhoto(Property $property, int $sortOrder): PropertyMedia
    {
        return PropertyMedia::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'type' => 'photo',
            'external_url' => 'https://images.example.test/photo-'.Str::random(6).'.jpg',
            'sort_order' => $sortOrder,
        ]);
    }
}