<?php

namespace App\ProcessScraping\Images\Generators;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ComfyUIClient
{
    public function isEnabled(): bool
    {
        return (bool) config('services.comfyui.enabled');
    }

    public function isReachable(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            return Http::timeout(5)
                ->get($this->baseUrl().'/system_stats')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array{prompt_id: string, number: int}
     */
    public function queuePrompt(array $workflow): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->post($this->baseUrl().'/prompt', [
                'prompt' => $workflow,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('ComfyUI HTTP '.$response->status().': '.$response->body());
        }

        $data = $response->json();

        if (! empty($data['error'])) {
            throw new RuntimeException('ComfyUI error: '.json_encode($data['error']));
        }

        if (! empty($data['node_errors'])) {
            throw new RuntimeException('ComfyUI node_errors: '.json_encode($data['node_errors']));
        }

        $promptId = (string) ($data['prompt_id'] ?? '');

        if ($promptId === '') {
            throw new RuntimeException('ComfyUI no devolvió prompt_id.');
        }

        return [
            'prompt_id' => $promptId,
            'number' => (int) ($data['number'] ?? 0),
        ];
    }

    /**
     * @return list<array{filename: string, subfolder: string, type: string}>
     */
    public function waitForImages(string $promptId): array
    {
        $timeout = (int) config('services.comfyui.timeout', 180);
        $deadline = microtime(true) + max($timeout, 30);
        $pollMs = (int) config('services.comfyui.poll_interval_ms', 500);

        while (microtime(true) < $deadline) {
            $images = $this->extractImagesFromHistory($this->getHistoryEntry($promptId));

            if ($images !== []) {
                return $images;
            }

            usleep(max($pollMs, 100) * 1000);
        }

        throw new RuntimeException("ComfyUI timeout esperando prompt_id={$promptId}.");
    }

    /**
     * @param  array{filename: string, subfolder: string, type: string}  $image
     */
    public function downloadImage(array $image): string
    {
        $response = Http::timeout(60)->get($this->baseUrl().'/view', [
            'filename' => $image['filename'],
            'subfolder' => $image['subfolder'],
            'type' => $image['type'],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('ComfyUI view HTTP '.$response->status());
        }

        return $response->body();
    }

    public function waitForQueueIdle(int $timeoutSeconds = 180): bool
    {
        $deadline = microtime(true) + max($timeoutSeconds, 10);

        while (microtime(true) < $deadline) {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->get($this->baseUrl().'/queue');
            } catch (\Throwable) {
                usleep(500_000);

                continue;
            }

            if ($response->successful()) {
                $running = $response->json('queue_running') ?? [];
                $pending = $response->json('queue_pending') ?? [];

                if ($running === [] && $pending === []) {
                    return true;
                }
            }

            usleep(500_000);
        }

        return false;
    }

    public function freeMemory(): bool
    {
        if (! $this->isEnabled() || ! $this->isReachable()) {
            return false;
        }

        $this->waitForQueueIdle(180);

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post($this->baseUrl().'/free', [
                    'unload_models' => true,
                    'free_memory' => true,
                ]);
        } catch (\Throwable $e) {
            Log::warning('comfyui: fallo al liberar memoria', ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('comfyui: /free HTTP '.$response->status(), ['body' => $response->body()]);

            return false;
        }

        $waitSeconds = max(0, (int) config('services.comfyui.free_memory_wait_seconds', 8));
        if ($waitSeconds > 0) {
            sleep($waitSeconds);
        }

        Log::info('comfyui: modelos descargados de VRAM (listo para Ollama)');

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getHistoryEntry(string $promptId): ?array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->get($this->baseUrl().'/history/'.$promptId);

        if ($response->failed()) {
            Log::warning('comfyui: history falló', [
                'prompt_id' => $promptId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $data = $response->json();

        return is_array($data[$promptId] ?? null) ? $data[$promptId] : null;
    }

    /**
     * @param  array<string, mixed>|null  $historyEntry
     * @return list<array{filename: string, subfolder: string, type: string}>
     */
    private function extractImagesFromHistory(?array $historyEntry): array
    {
        if ($historyEntry === null) {
            return [];
        }

        $outputs = $historyEntry['outputs'] ?? [];
        $images = [];

        foreach ($outputs as $nodeOutput) {
            if (! is_array($nodeOutput) || ! isset($nodeOutput['images']) || ! is_array($nodeOutput['images'])) {
                continue;
            }

            foreach ($nodeOutput['images'] as $image) {
                if (! is_array($image) || empty($image['filename'])) {
                    continue;
                }

                $images[] = [
                    'filename' => (string) $image['filename'],
                    'subfolder' => (string) ($image['subfolder'] ?? ''),
                    'type' => (string) ($image['type'] ?? 'output'),
                ];
            }
        }

        return $images;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.comfyui.url'), '/');
    }
}
