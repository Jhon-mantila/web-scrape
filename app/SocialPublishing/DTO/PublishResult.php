<?php

namespace App\SocialPublishing\DTO;

class PublishResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $externalId = null,
        public readonly ?string $externalUrl = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $error = null,
    ) {}

    public static function ok(string $externalId, ?string $externalUrl = null, ?array $raw = null): self
    {
        return new self(true, $externalId, $externalUrl, $raw);
    }

    public static function fail(string $error, ?array $raw = null, ?string $externalId = null): self
    {
        return new self(false, $externalId, null, $raw, $error);
    }
}
