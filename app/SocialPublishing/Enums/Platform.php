<?php

namespace App\SocialPublishing\Enums;

enum Platform: string
{
    case Youtube = 'youtube';
    case FacebookEsquinaweb = 'facebook_esquinaweb';
    case FacebookEsquinagamers = 'facebook_esquinagamers';
    case Tiktok = 'tiktok';

    public function label(): string
    {
        return config("social.platforms.{$this->value}.label", $this->value);
    }

    public function isEnabled(): bool
    {
        return (bool) config("social.platforms.{$this->value}.enabled", false);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
