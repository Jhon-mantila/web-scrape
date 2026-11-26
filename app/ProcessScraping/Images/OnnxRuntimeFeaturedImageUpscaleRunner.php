<?php

namespace App\ProcessScraping\Images;

use App\ProcessScraping\Images\Contracts\FeaturedImageUpscaleRunner;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class OnnxRuntimeFeaturedImageUpscaleRunner implements FeaturedImageUpscaleRunner
{
    public function run(string $inputPath, string $outputPath, string $modelPath, int $maxSide): array
    {
        $python = $this->pythonBinary();
        $script = base_path('app/ProcessScraping/Images/bin/realesrgan_upscale.py');

        if ($python === null) {
            return ['ok' => false, 'error' => 'python_not_found'];
        }

        if (! is_file($script)) {
            return ['ok' => false, 'error' => 'script_missing'];
        }

        $process = new Process([
            $python,
            $script,
            '--input',
            $inputPath,
            '--output',
            $outputPath,
            '--model',
            $modelPath,
            '--max-side',
            (string) max(512, $maxSide),
        ]);
        $process->setTimeout(max(30, (int) config('services.featured_image.upscale_timeout', 120)));
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput().' '.$process->getOutput());
            Log::warning('featured_image: realesrgan falló', [
                'error' => mb_substr($error, 0, 800),
                'code' => $process->getExitCode(),
            ]);

            return ['ok' => false, 'error' => $error !== '' ? $error : 'upscale_failed'];
        }

        return ['ok' => true, 'error' => null];
    }

    private function pythonBinary(): ?string
    {
        $configured = (string) config('services.featured_image.upscale_python', '');
        $candidates = array_filter([
            $configured,
            storage_path('app/private/realesrgan/venv/bin/python'),
            '/opt/realesrgan/bin/python',
            'python3',
            'python',
        ]);

        foreach ($candidates as $binary) {
            if ($binary === 'python3' || $binary === 'python') {
                $probe = new Process([$binary, '-c', 'import onnxruntime, PIL, numpy']);
                $probe->setTimeout(15);
                $probe->run();
                if ($probe->isSuccessful()) {
                    return $binary;
                }

                continue;
            }

            if (is_file($binary) && is_executable($binary)) {
                return $binary;
            }
        }

        return null;
    }
}
