<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Fixtures;

final class AppContext
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $agent = null,
        private readonly ?string $direction = null,
        private readonly ?string $operation = null,
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

    public function getDirection(): ?string
    {
        return $this->direction;
    }

    public function getOperation(): ?string
    {
        return $this->operation;
    }
}
