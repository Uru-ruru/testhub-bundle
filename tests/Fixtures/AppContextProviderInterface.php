<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Fixtures;

/**
 * Mirrors an application's own provider interface, unrelated to the bundle's one.
 */
interface AppContextProviderInterface
{
    /**
     * @return AppContext[]
     */
    public function get(): array;
}
