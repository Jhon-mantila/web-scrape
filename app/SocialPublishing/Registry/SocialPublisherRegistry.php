<?php

namespace App\SocialPublishing\Registry;

use App\SocialPublishing\Contracts\SocialPublisherInterface;
use InvalidArgumentException;

class SocialPublisherRegistry
{
    /** @var array<string, SocialPublisherInterface> */
    private array $publishers = [];

    public function register(SocialPublisherInterface $publisher): void
    {
        $this->publishers[$publisher->platform()] = $publisher;
    }

    public function get(string $platform): SocialPublisherInterface
    {
        if (! isset($this->publishers[$platform])) {
            throw new InvalidArgumentException("Publisher no registrado: {$platform}");
        }

        return $this->publishers[$platform];
    }

    /**
     * @return array<string, SocialPublisherInterface>
     */
    public function all(): array
    {
        return $this->publishers;
    }

    public function has(string $platform): bool
    {
        return isset($this->publishers[$platform]);
    }
}
