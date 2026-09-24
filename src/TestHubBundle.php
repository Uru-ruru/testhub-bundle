<?php

declare(strict_types=1);

namespace TestHub\Bundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class TestHubBundle extends Bundle
{
    /**
     * The bundle root (not src/), so templates/ is registered as the "@TestHub" Twig namespace.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
