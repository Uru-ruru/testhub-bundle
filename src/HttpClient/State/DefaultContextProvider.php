<?php

declare(strict_types=1);

namespace TestHub\Bundle\HttpClient\State;

class DefaultContextProvider implements ContextProviderInterface
{
    public function get(): array
    {
        return [];
    }
}
