<?php

namespace App\SocialPublishing\Enums;

enum VideoCaptionAiProvider: string
{
    case OllamaLocal = 'ollama_local';
    case Deepseek = 'deepseek';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::OllamaLocal => 'Ollama local',
            self::Deepseek => 'DeepSeek (API de pago)',
            self::None => 'Ninguna (sin IA)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::OllamaLocal;
    }
}
