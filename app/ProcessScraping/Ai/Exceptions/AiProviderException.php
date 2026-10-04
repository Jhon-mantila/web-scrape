<?php

namespace App\ProcessScraping\Ai\Exceptions;

use RuntimeException;
use Throwable;

class AiProviderException extends RuntimeException
{
    public const KIND_QUOTA = 'quota_exceeded';

    public const KIND_RATE_LIMIT = 'rate_limit';

    public const KIND_AUTH = 'auth';

    public const KIND_CONNECTION = 'connection';

    public const KIND_HTTP = 'http';

    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $kind,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function userMessage(): string
    {
        $label = $this->providerLabel();

        return match ($this->kind) {
            self::KIND_QUOTA => "{$label}: se agotó la cuota o el saldo del plan. Revisa tu cuenta en el panel del proveedor o cambia a Ollama local (`AI_PROVIDER=ollama_local`).",
            self::KIND_RATE_LIMIT => "{$label}: límite de peticiones alcanzado. Espera unos minutos o cambia de proveedor.",
            self::KIND_AUTH => "{$label}: API key inválida o sin permisos. Revisa `DEEPSEEK_API_KEY` / `OLLAMA_API_KEY` en `.env`.",
            self::KIND_CONNECTION => "{$label}: no se pudo conectar con el servicio. Comprueba red, URL y que el servicio esté activo.",
            default => "{$label}: ".$this->getMessage(),
        };
    }

    private function providerLabel(): string
    {
        return match ($this->provider) {
            'deepseek' => 'DeepSeek API',
            'ollama_api' => 'Ollama API',
            'ollama_local' => 'Ollama local',
            default => ucfirst(str_replace('_', ' ', $this->provider)),
        };
    }
}
