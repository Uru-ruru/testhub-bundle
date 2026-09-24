<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Fixtures;

final class AppContext
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $agent = null,
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAgent(): ?string
    {
        return $this->agent;
    }
}
