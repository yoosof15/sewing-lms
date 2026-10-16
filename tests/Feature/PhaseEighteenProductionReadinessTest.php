<?php

namespace Tests\Feature;

use App\Jobs\ProcessLessonVideo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ControlledFailingReadinessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        throw new \RuntimeException('Controlled Phase 18 queue rehearsal failure.');
    }
}

class PhaseEighteenProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_is_read_only_and_redacts_application_key(): void
    {
        $before = DB::table('users')->count();
        $exit = Artisan::call('app:preflight');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertSame($before, DB::table('users')->count());
        $this->assertStringContainsString('PASS', $output);
        $this->assertStringContainsString('configured (redacted)', $output);
        $this->assertStringNotContainsString((string) config('app.key'), $output);
    }

    public function test_production_preflight_fails_for_debug_http_and_insecure_cookie(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', true);
        config()->set('app.url', 'http://example.test');
        config()->set('session.secure', false);

        $exit = Artisan::call('app:preflight', ['--production' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL  Debug mode', $output);
        $this->assertStringContainsString('FAIL  HTTPS application URL', $output);
        $this->assertStringNotContainsString('APP_KEY=', $output);
    }

    public function test_production_error_page_does_not_expose_paths_or_environment_values(): void
    {
        $this->app['env'] = 'production';
        config()->set('app.debug', false);

        $response = $this->get('/missing-phase-18-route');

        $response->assertNotFound();
        $response->assertDontSee(base_path(), false);
        $response->assertDontSee((string) config('app.key'), false);
        $response->assertDontSee('APP_DEBUG', false);
        $response->assertDontSee('SQLSTATE', false);
    }

    public function test_smoke_check_cleans_its_temporary_private_file(): void
    {
        Storage::fake('local');

        $this->artisan('app:smoke', ['--write-storage' => true])
            ->expectsOutputToContain('PASS  database')
            ->expectsOutputToContain('PASS  private storage')
            ->assertSuccessful();

        Storage::disk('local')->assertDirectoryEmpty('smoke');
    }

    public function test_session_pruning_supports_dry_run_and_deletes_only_expired_rows(): void
    {
        config()->set('session.driver', 'database');
        DB::table('sessions')->insert([
            ['id' => 'expired', 'payload' => '', 'last_activity' => now()->subHours(3)->timestamp],
            ['id' => 'current', 'payload' => '', 'last_activity' => now()->timestamp],
        ]);

        $this->artisan('sessions:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('sessions', ['id' => 'expired']);
        $this->artisan('sessions:prune')->assertSuccessful();
        $this->assertDatabaseMissing('sessions', ['id' => 'expired']);
        $this->assertDatabaseHas('sessions', ['id' => 'current']);
    }

    public function test_video_processing_job_uses_dedicated_configurable_queue(): void
    {
        config()->set('video.processing_queue', 'transcode-test');

        $job = new ProcessLessonVideo(123);

        $this->assertSame('transcode-test', $job->queue);
    }

    public function test_failed_job_inventory_retry_and_forget_are_rehearsed_on_test_database(): void
    {
        config()->set('queue.default', 'database');
        ControlledFailingReadinessJob::dispatch();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
        $failure = DB::table('failed_jobs')->first();
        $this->assertNotNull($failure);
        $this->artisan('queue:failed')->expectsOutputToContain(ControlledFailingReadinessJob::class)->assertSuccessful();

        $this->artisan('queue:retry', ['id' => [$failure->uuid]])->assertSuccessful();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $failure->uuid]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
        $retriedFailure = DB::table('failed_jobs')->first();
        $this->assertNotNull($retriedFailure);
        $this->artisan('queue:forget', ['id' => $retriedFailure->uuid])->assertSuccessful();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $retriedFailure->uuid]);
    }

    public function test_admin_create_is_interactive_validated_and_does_not_echo_password(): void
    {
        $this->seed(RoleSeeder::class);
        $password = 'StrongPhase18Password123';

        $this->artisan('admin:create')
            ->expectsQuestion('First name', 'Release')
            ->expectsQuestion('Last name', 'Admin')
            ->expectsQuestion('Email', 'release-admin@example.test')
            ->expectsQuestion('Iranian mobile', '09121234567')
            ->expectsQuestion('Password (minimum 12 characters)', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutputToContain('Administrator created')
            ->doesntExpectOutput($password)
            ->assertSuccessful();

        $admin = User::where('email', 'release-admin@example.test')->firstOrFail();
        $this->assertTrue($admin->hasRole('ADMIN'));
        $this->assertNotSame($password, $admin->password);
    }
}
