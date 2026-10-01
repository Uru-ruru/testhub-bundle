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
                ->scalarNode('sandbox_cafile')
                    ->info('CA bundle used to verify the sandbox certificate. Applied only to requests sent to the sandbox.')
                    ->defaultValue('%env(string:default::SANDBOX_CAFILE)%')
                ->end()
                ->booleanNode('sandbox_verify_peer')
                    ->info('Set to false to skip certificate and host name checks for the sandbox, e.g. for a self-signed certificate. Requests to other hosts are still verified.')
                    ->defaultTrue()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
