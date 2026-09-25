<?php

declare(strict_types=1);

namespace TestHub\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('test_hub');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('environments')
                    ->info('Kernel environments in which the bundle is active. In any other environment it registers nothing.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['dev'])
                ->end()
                ->scalarNode('context_provider')
                    ->defaultNull()
                    ->info('The service ID or class name of the ContextProviderInterface implementation. When empty, the application\'s own implementation is detected, with DefaultContextProvider as fallback.')
                ->end()
                ->scalarNode('sandbox_url')
                    ->defaultValue('%env(string:default::SANDBOX_URL)%')
                ->end()
                ->scalarNode('use_sandbox')
                    ->info('Default sandbox state. The "Use sandbox" switch in the profiler overrides it per browser.')
                    ->defaultValue('%env(bool:default::USE_SANDBOX)%')
                ->end()
                ->scalarNode('api_key')
                    ->defaultValue('%env(string:default::SANDBOX_API_KEY)%')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
