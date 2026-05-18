<?php

declare(strict_types=1);

namespace TestHub\Bundle\HttpClient\State;

interface ContextProviderInterface
{
    /**
     * @return array<mixed>
     */
    public function get(): array;
}
