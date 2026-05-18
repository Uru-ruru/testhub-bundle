<?php

declare(strict_types=1);

namespace TestHub\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use TestHub\Bundle\HttpClient\State\DefaultContextProvider;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('test_hub');

        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('context_provider')
            ->defaultValue(DefaultContextProvider::class)
            ->info('The service ID or class name of the ContextProviderInterface implementation.')
            ->end()
            ->scalarNode('sandbox_url')
            ->defaultValue('%env(SANDBOX_URL)%')
            ->end()
            ->scalarNode('use_sandbox')
            ->defaultValue('%env(USE_SANDBOX)%')
            ->end()
            ->end();

        return $treeBuilder;
    }
}
