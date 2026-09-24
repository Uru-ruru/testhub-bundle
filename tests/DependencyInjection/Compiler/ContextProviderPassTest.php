<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\DependencyInjection\Compiler\ContextProviderPass;
use TestHub\Bundle\DependencyInjection\TestHubExtension;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClient\State\DefaultContextProvider;
use TestHub\Bundle\Tests\Fixtures\AppContextCollector;
use TestHub\Bundle\Tests\Functional\App\AgentContextProvider;

class ContextProviderPassTest extends TestCase
{
    public function testFallsBackToDefaultProvider(): void
    {
        $container = $this->process();

        $this->assertSame(DefaultContextProvider::class, $this->resolvedProvider($container));
    }

    public function testDetectsApplicationImplementation(): void
    {
        $container = $this->process(static function (ContainerBuilder $container): void {
            $container->register('app.context_provider', AgentContextProvider::class)->setAutoconfigured(true);
        });

        $this->assertSame('app.context_provider', $this->resolvedProvider($container));
        $this->assertSame('app.context_provider', $container->getDefinition(SandBoxDataCollector::class)->getArgument(2));
    }

    public function testConfiguredProviderWins(): void
    {
        $container = $this->process(static function (ContainerBuilder $container): void {
            $container->register('app.context_provider', AgentContextProvider::class)->setAutoconfigured(true);
            $container->register('app.other_provider', AgentContextProvider::class);
        }, ['context_provider' => 'app.other_provider']);

        $this->assertSame('app.other_provider', $this->resolvedProvider($container));
    }

    public function testConfiguredProviderMayUseItsOwnInterface(): void
    {
        $container = $this->process(static function (ContainerBuilder $container): void {
            $container->register(AppContextCollector::class)->setAutoconfigured(true);
        }, ['context_provider' => AppContextCollector::class]);

        $this->assertSame(AppContextCollector::class, $this->resolvedProvider($container));
    }

    public function testProviderWithoutGetMethodIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('get(): array');

        $this->process(static function (ContainerBuilder $container): void {
            $container->register('app.not_a_provider', \stdClass::class);
        }, ['context_provider' => 'app.not_a_provider']);
    }

    public function testApplicationAliasWins(): void
    {
        $container = $this->process(static function (ContainerBuilder $container): void {
            $container->register('app.context_provider', AgentContextProvider::class)->setAutoconfigured(true);
            $container->register('app.other_provider', AgentContextProvider::class);
            $container->setAlias(ContextProviderInterface::class, 'app.other_provider');
        });

        $this->assertSame('app.other_provider', $this->resolvedProvider($container));
    }

    public function testSeveralImplementationsAreAmbiguous(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('test_hub.context_provider');

        $this->process(static function (ContainerBuilder $container): void {
            $container->register('app.first_provider', AgentContextProvider::class)->setAutoconfigured(true);
            $container->register('app.second_provider', AgentContextProvider::class)->setAutoconfigured(true);
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function process(?callable $appServices = null, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'dev');
        (new TestHubExtension())->load([$config], $container);

        // Application services are registered on top of the extension, like MergeExtensionConfigurationPass does.
        if ($appServices) {
            $appServices($container);
        }

        (new ResolveInstanceofConditionalsPass())->process($container);
        (new ContextProviderPass())->process($container);

        return $container;
    }

    private function resolvedProvider(ContainerBuilder $container): string
    {
        return (string) $container->getAlias(ContextProviderInterface::class);
    }
}
