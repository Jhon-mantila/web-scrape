<?php

namespace App\SocialPublishing\Enums;

enum WordpressSite: string
{
    case Esquinaweb = 'esquinaweb';
    case Esquinagamers = 'esquinagamers';
    case Esquinaanime = 'esquinaanime';

    public function label(): string
    {
        return (string) config("wordpress_sources.sites.{$this->value}.label", $this->value);
    }

    public function url(): ?string
    {
        $url = config("wordpress_sources.sites.{$this->value}.url");

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    public function isEnabled(): bool
    {
        return (bool) config("wordpress_sources.sites.{$this->value}.enabled", false)
            && ! $this->isComingSoon()
            && $this->url() !== null;
    }

    public function isComingSoon(): bool
    {
        return (bool) config("wordpress_sources.sites.{$this->value}.coming_soon", false);
    }

    public function facebookPlatform(): ?string
    {
        $platform = config("wordpress_sources.sites.{$this->value}.facebook_platform");

        return is_string($platform) && $platform !== '' ? $platform : null;
    }

    /**
     * @return list<string>
     */
    public function allowedPlatforms(): array
    {
        $platforms = config('wordpress_sources.linkedin_platforms', ['linkedin', 'linkedin_jessika']);

        if ($this->facebookPlatform() !== null) {
            array_unshift($platforms, $this->facebookPlatform());
        }

        return array_values(array_unique($platforms));
    }

    public function allowsPlatform(string $platform): bool
    {
        return in_array($platform, $this->allowedPlatforms(), true);
    }

    public static function tryFromEnabled(string $site): ?self
    {
        $enum = self::tryFrom($site);

        return $enum !== null && $enum->isEnabled() ? $enum : null;
    }

    /**
     * @return list<array{key: string, label: string, enabled: bool, coming_soon: bool}>
     */
    public static function optionsForUi(): array
    {
        return array_values(array_map(
            fn (self $site) => [
                'key' => $site->value,
                'label' => $site->label(),
                'enabled' => $site->isEnabled(),
                'coming_soon' => $site->isComingSoon(),
            ],
            self::cases(),
        ));
    }
}
