<?php

namespace App\ProcessScraping\Images\Generators;

use RuntimeException;

class FluxSchnellWorkflow
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $prompt, ?int $seed = null): array
    {
        $path = (string) config('services.comfyui.workflow_path');

        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException("Workflow ComfyUI no encontrado: {$path}");
        }

        $workflow = json_decode((string) file_get_contents($path), true);

        if (! is_array($workflow)) {
            throw new RuntimeException('Workflow ComfyUI inválido (JSON).');
        }

        $promptNode = (string) config('services.comfyui.prompt_node', '6');
        $samplerNode = (string) config('services.comfyui.sampler_node', '3');
        $latentNode = (string) config('services.comfyui.latent_node', '5');

        if (! isset($workflow[$promptNode]['inputs']['text'])) {
            throw new RuntimeException("Nodo de prompt '{$promptNode}' no encontrado en el workflow.");
        }

        $workflow[$promptNode]['inputs']['text'] = $prompt;

        if (isset($workflow[$samplerNode]['inputs']['seed'])) {
            $workflow[$samplerNode]['inputs']['seed'] = $seed ?? random_int(1, PHP_INT_MAX);
        }

        if (isset($workflow[$samplerNode]['inputs']['steps'])) {
            $workflow[$samplerNode]['inputs']['steps'] = (int) config('services.comfyui.steps', 4);
        }

        if (isset($workflow[$latentNode]['inputs']['width'], $workflow[$latentNode]['inputs']['height'])) {
            $workflow[$latentNode]['inputs']['width'] = (int) config('services.comfyui.width', 1216);
            $workflow[$latentNode]['inputs']['height'] = (int) config('services.comfyui.height', 684);
        }

        $unetNode = (string) config('services.comfyui.unet_node', '12');
        if (isset($workflow[$unetNode]['inputs']['unet_name'])) {
            $workflow[$unetNode]['inputs']['unet_name'] = (string) config(
                'services.comfyui.unet_name',
                'flux1-schnell.safetensors'
            );
        }

        $vaeNode = (string) config('services.comfyui.vae_node', '10');
        if (isset($workflow[$vaeNode]['inputs']['vae_name'])) {
            $workflow[$vaeNode]['inputs']['vae_name'] = (string) config(
                'services.comfyui.vae_name',
                'ae.safetensors'
            );
        }

        $clipNode = (string) config('services.comfyui.clip_node', '11');
        if (isset($workflow[$clipNode]['inputs']['clip_name1'], $workflow[$clipNode]['inputs']['clip_name2'])) {
            $workflow[$clipNode]['inputs']['clip_name1'] = (string) config(
                'services.comfyui.clip_name1',
                'clip_l.safetensors'
            );
            $workflow[$clipNode]['inputs']['clip_name2'] = (string) config(
                'services.comfyui.clip_name2',
                't5xxl_fp16.safetensors'
            );
        }

        return $workflow;
    }
}
