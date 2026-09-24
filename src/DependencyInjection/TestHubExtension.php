<?php

declare(strict_types=1);

namespace TestHub\Bundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\DependencyInjection\Compiler\ContextProviderPass;
use TestHub\Bundle\HttpClient\SandBoxRequestLog;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClient\State\DefaultContextProvider;
use TestHub\Bundle\HttpClientDecorator\SandBoxHttpClientDecorator;
use TestHub\Bundle\Service\SandBoxService;
use TestHub\Bundle\Twig\SandBoxProfilerExtension;
use Twig\Extension\AbstractExtension;

class TestHubExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $environment = $container->hasParameter('kernel.environment') ? $container->getParameter('kernel.environment') : null;
        if (null !== $environment && !\in_array($environment, $config['environments'], true)) {
            return;
        }

        $container->register(SandBoxService::class)
            ->setArgument('$apiKey', $config['api_key'])
            ->setArgument('$useSandbox', $config['use_sandbox'])
            ->setArgument('$sandboxUrl', $config['sandbox_url'])
            ->setPublic(true);

        $container->register(SandBoxRequestLog::class)
            ->addTag('kernel.reset', ['method' => 'reset']);

        $container->register(SandBoxDataCollector::class)
            ->setArguments([
                new Reference(SandBoxService::class),
                new Reference(SandBoxRequestLog::class),
                DefaultContextProvider::class,
            ])
            ->addTag('data_collector', [
                'template' => SandBoxDataCollector::getTemplate(),
                'id' => SandBoxDataCollector::NAME,
                'priority' => 250,
            ]);

        if (class_exists(AbstractExtension::class)) {
            $container->register(SandBoxProfilerExtension::class)
                ->setArguments([
                    new ServiceClosureArgument(new Reference('profiler', ContainerInterface::NULL_ON_INVALID_REFERENCE)),
                ])
                ->addTag('twig.extension');
        }

        // Tags the application's implementations so ContextProviderPass can detect them.
        $container->registerForAutoconfiguration(ContextProviderInterface::class)
            ->addTag(ContextProviderPass::TAG);

        $container->setParameter(ContextProviderPass::CONFIGURED_PARAMETER, $config['context_provider']);
        $contextProviderId = $config['context_provider'] ?? DefaultContextProvider::class;

        if (class_exists($contextProviderId) && !$container->has($contextProviderId)) {
            $container->register($contextProviderId);
        }

        $container->setAlias(ContextProviderInterface::class, $contextProviderId);

        $container->register(SandBoxHttpClientDecorator::class)
            ->setDecoratedService('http_client.transport', null, 100)
            ->setArguments([
                new Reference('.inner'),
                // Lazy: the application's provider may itself depend on http_client.
                new ServiceClosureArgument(new Reference(ContextProviderInterface::class)),
                new Reference(SandBoxService::class),
                new Reference('request_stack'),
                new Reference(SandBoxRequestLog::class),
            ]);
    }
}
