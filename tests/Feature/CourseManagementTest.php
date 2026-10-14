<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['title' => 'Sewing Basics', 'category_id' => null, 'short_description' => 'Short', 'description' => 'Description', 'status' => 'DRAFT', 'teacher_ids' => []], $overrides);
    }

    public function test_admin_can_create_course(): void
    {
        $this->actingAs($this->user('ADMIN'))->post('/admin/courses', $this->payload())->assertRedirect();
        $this->assertDatabaseHas('courses', ['title' => 'Sewing Basics', 'status' => 'DRAFT']);
    }

    public function test_admin_can_update_course_and_change_status(): void
    {
        $course = Course::factory()->create();
        $this->actingAs($this->user('ADMIN'))->put("/admin/courses/{$course->id}", $this->payload(['title' => 'Updated', 'status' => 'PUBLISHED']))->assertRedirect();
        $this->assertSame(CourseStatus::Published, $course->fresh()->status);
        $this->assertNotNull($course->fresh()->published_at);
    }

    public function test_duplicate_slug_is_handled_and_slug_stays_stable_on_title_change(): void
    {
        $first = Course::create($this->payload());
        $second = Course::create($this->payload());
        $this->assertSame('sewing-basics', $first->slug);
        $this->assertSame('sewing-basics-2', $second->slug);
        $first->update(['title' => 'Different']);
        $this->assertSame('sewing-basics', $first->fresh()->slug);
    }

    public function test_persian_title_gets_non_empty_unique_fallback_slug(): void
    {
        $a = Course::create($this->payload(['title' => 'آموزش خیاطی']));
        $b = Course::create($this->payload(['title' => 'آموزش خیاطی']));
        $this->assertNotSame('', $a->slug);
        $this->assertNotSame($a->slug, $b->slug);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->actingAs($this->user('ADMIN'))->post('/admin/courses', $this->payload(['status' => 'UNKNOWN']))->assertSessionHasErrors('status');
    }

    public function test_cover_validation_and_storage_work(): void
    {
        Storage::fake('public');
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post('/admin/courses', $this->payload(['cover_image' => UploadedFile::fake()->create('bad.pdf', 20, 'application/pdf')]))->assertSessionHasErrors('cover_image');
        $this->actingAs($admin)->post('/admin/courses', $this->payload(['title' => 'Covered', 'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 600)]))->assertRedirect();
        Storage::disk('public')->assertExists(Course::whereTitle('Covered')->value('cover_image'));
    }

    public function test_replacing_cover_removes_old_file(): void
    {
        Storage::fake('public');
        $old = UploadedFile::fake()->image('old.jpg')->store('course-covers', 'public');
        $course = Course::factory()->create(['cover_image' => $old]);
        $this->actingAs($this->user('ADMIN'))->post("/admin/courses/{$course->id}", $this->payload(['title' => $course->title, '_method' => 'put', 'cover_image' => UploadedFile::fake()->image('new.jpg')]))->assertRedirect();
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($course->fresh()->cover_image);
    }

    public function test_soft_deleted_catalog_records_are_hidden_from_normal_queries(): void
    {
        $category = CourseCategory::factory()->create();
        $course = Course::factory()->create(['category_id' => $category->id]);
        $course->delete();
        $category->delete();
        $this->assertNull(Course::find($course->id));
        $this->assertNull(CourseCategory::find($category->id));
        $this->assertNotNull(Course::withTrashed()->find($course->id));
        $this->assertNotNull(CourseCategory::withTrashed()->find($category->id));
    }
}
