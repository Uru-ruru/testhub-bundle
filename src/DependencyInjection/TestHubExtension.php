<?php

declare(strict_types=1);

namespace TestHub\Bundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClientDecorator\SandBoxHttpClientDecorator;
use TestHub\Bundle\Service\SandBoxService;

class TestHubExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Register SandBoxService
        $container->register(SandBoxService::class)
            ->setArgument('$apiKey', '%env(default::SANDBOX_API_KEY)%')
            ->setArgument('$useSandbox', $config['use_sandbox'])
            ->setArgument('$sandboxUrl', $config['sandbox_url'])
            ->setPublic(true);

        // Register DataCollector
        $container->register(SandBoxDataCollector::class)
            ->setArgument('$sandboxService', new Reference(SandBoxService::class))
            ->addTag('data_collector', [
                'template' => 'profiler/sandbox_collector.html.twig',
                'id' => 'app.sandbox_collector',
            ])
            ->setPublic(true);

        // Context Provider Alias/Definition
        $contextProviderId = $config['context_provider'];
        if (class_exists($contextProviderId) && ! $container->has($contextProviderId)) {
            $container->register($contextProviderId);
        }
        
        $container->setAlias(ContextProviderInterface::class, $contextProviderId);

        // Register Decorator
        $container->register(SandBoxHttpClientDecorator::class)
            ->setDecoratedService('http_client.transport', null, 100)
            ->setArguments([
                new Reference('.inner'),
                new Reference(ContextProviderInterface::class),
                new Reference(SandBoxService::class),
                new Reference('request_stack'),
            ]);
    }
}
