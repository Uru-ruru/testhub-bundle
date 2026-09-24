<?php

declare(strict_types=1);

namespace TestHub\Bundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use TestHub\Bundle\DependencyInjection\Compiler\ContextProviderPass;

class TestHubBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ContextProviderPass());
    }

    /**
     * The bundle root (not src/), so templates/ is registered as the "@TestHub" Twig namespace.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
