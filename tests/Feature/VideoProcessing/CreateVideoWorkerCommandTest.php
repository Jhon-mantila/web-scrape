<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\User;
use App\Models\VideoWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateVideoWorkerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_worker_and_prints_plaintext_token_once(): void
    {
        $user = User::factory()->create(['email' => 'jhon@example.com']);

        $this->artisan('video-worker:create', [
            'name' => 'PC-Jhon',
            '--user' => $user->email,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('PC-Jhon');

        $worker = VideoWorker::query()->first();
        $this->assertNotNull($worker);
        $this->assertSame($user->id, $worker->user_id);
        $this->assertSame('PC-Jhon', $worker->name);
        $this->assertTrue($worker->is_enabled);
        $this->assertSame(64, strlen((string) $worker->token_hash));
        $this->assertNull($worker->getAttribute('token'));
    }

    public function test_printed_token_matches_stored_hash(): void
    {
        $user = User::factory()->create();

        $this->withoutMockingConsoleOutput();
        $status = $this->artisan('video-worker:create', [
            'name' => 'WSL',
            '--user' => (string) $user->id,
        ]);
        $this->assertSame(0, $status);

        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertMatchesRegularExpression('/ESQUINA_WORKER_TOKEN=([A-Za-z0-9]+)/', $output);
        preg_match('/ESQUINA_WORKER_TOKEN=([A-Za-z0-9]+)/', $output, $matches);

        $worker = VideoWorker::query()->first();
        $this->assertNotNull($worker);
        $this->assertSame(VideoWorker::hashToken($matches[1]), $worker->token_hash);
        $this->assertStringNotContainsString($matches[1], (string) json_encode($worker->toArray()));
    }

    public function test_missing_user_explains_empty_database(): void
    {
        $this->artisan('video-worker:create', [
            'name' => 'PC-Jhon',
            '--user' => 'jhon.e.mantilla@gmail.com',
        ])
            ->assertFailed()
            ->expectsOutputToContain('/register');
    }
}
