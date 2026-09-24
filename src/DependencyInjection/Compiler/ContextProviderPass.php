<?php

declare(strict_types=1);

namespace TestHub\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClient\State\DefaultContextProvider;

/**
 * Chooses the ContextProviderInterface implementation, in this order:
 *  1. test_hub.context_provider, when configured;
 *  2. an alias for ContextProviderInterface defined by the application;
 *  3. the application's only autoconfigured ContextProviderInterface service;
 *  4. DefaultContextProvider.
 */
final class ContextProviderPass implements CompilerPassInterface
{
    public const string TAG = 'test_hub.context_provider';
    public const string CONFIGURED_PARAMETER = 'test_hub.context_provider';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(ContextProviderInterface::class)) {
            return;
        }

        $currentId = (string) $container->getAlias(ContextProviderInterface::class);

        if (null === $container->getParameter(self::CONFIGURED_PARAMETER) && DefaultContextProvider::class === $currentId) {
            $candidates = array_values(array_filter(
                array_keys($container->findTaggedServiceIds(self::TAG)),
                static fn (string $id) => DefaultContextProvider::class !== $id && !$container->getDefinition($id)->isAbstract(),
            ));

            if (\count($candidates) > 1) {
                throw new LogicException(\sprintf('Several services implement "%s" (%s). Choose one with the "test_hub.context_provider" option.', ContextProviderInterface::class, implode(', ', $candidates)));
            }

            if ($candidates) {
                $currentId = $candidates[0];
                $container->setAlias(ContextProviderInterface::class, $currentId);
            }
        }

        $this->assertHasGetMethod($container, $currentId);

        if ($container->hasDefinition(SandBoxDataCollector::class)) {
            $container->getDefinition(SandBoxDataCollector::class)->replaceArgument(2, $currentId);
        }
    }

    /**
     * The provider does not have to implement ContextProviderInterface, since the bundle is usually dev-only
     * and the application's class must load without it. It only needs get(): array.
     */
    private function assertHasGetMethod(ContainerBuilder $container, string $id): void
    {
        $class = $container->findDefinition($id)->getClass() ?? $id;
        $class = $container->getParameterBag()->resolveValue($class);

        if (\is_string($class) && class_exists($class) && !method_exists($class, 'get')) {
            throw new LogicException(\sprintf('The Test Hub context provider "%s" (%s) must have a public get(): array method.', $id, $class));
        }
    }
}
