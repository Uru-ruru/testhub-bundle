<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Fixtures;

/**
 * Mirrors an application collector: one context per outgoing request, not implementing the bundle interface.
 */
final class AppContextCollector implements AppContextProviderInterface
{
    /** @var AppContext[] */
    private array $contexts = [];

    public function collect(AppContext $context): void
    {
        $this->contexts[] = $context;
    }

    public function get(): array
    {
        return $this->contexts;
    }
}
