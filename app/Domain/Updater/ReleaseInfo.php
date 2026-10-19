<?php

namespace App\Domain\Updater;

final class ReleaseInfo
{
    public function __construct(
        public readonly string $version,
        public readonly string $channel,
        public readonly string $packageUrl,
        public readonly string $manifestUrl,
        public readonly string $signatureUrl,
        public readonly ?string $changelog = null,
        public readonly ?string $publishedAt = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
