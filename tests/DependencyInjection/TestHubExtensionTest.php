<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\DependencyInjection\TestHubExtension;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClient\State\DefaultContextProvider;
use TestHub\Bundle\HttpClientDecorator\SandBoxHttpClientDecorator;
use TestHub\Bundle\Service\SandBoxService;

class TestHubExtensionTest extends TestCase
{
    private TestHubExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new TestHubExtension();
    }

    public function testLoadDefaultConfiguration(): void
    {
        $container = $this->createContainer('dev');
        $this->extension->load([], $container);

        $this->assertTrue($container->has(SandBoxService::class));
        $this->assertTrue($container->has(SandBoxHttpClientDecorator::class));
        $this->assertTrue($container->getDefinition(SandBoxDataCollector::class)->hasTag('data_collector'));

        $this->assertTrue($container->hasAlias(ContextProviderInterface::class));
        $this->assertEquals(DefaultContextProvider::class, (string) $container->getAlias(ContextProviderInterface::class));
        $this->assertTrue($container->has(DefaultContextProvider::class));

        $definition = $container->getDefinition(SandBoxService::class);
        $this->assertEquals('%env(bool:default::USE_SANDBOX)%', $definition->getArgument('$useSandbox'));
        $this->assertEquals('%env(string:default::SANDBOX_URL)%', $definition->getArgument('$sandboxUrl'));
    }

    public function testNothingIsRegisteredOutsideDev(): void
    {
        $container = $this->createContainer('prod');
        $this->extension->load([], $container);

        $this->assertFalse($container->has(SandBoxService::class));
        $this->assertFalse($container->has(SandBoxHttpClientDecorator::class));
        $this->assertFalse($container->has(SandBoxDataCollector::class));
    }

    public function testEnvironmentsAreConfigurable(): void
    {
        $container = $this->createContainer('test');
        $this->extension->load([['environments' => ['dev', 'test']]], $container);

        $this->assertTrue($container->has(SandBoxHttpClientDecorator::class));
    }

    public function testLoadWithCustomContextProvider(): void
    {
        $container = $this->createContainer('dev');
        $this->extension->load([['context_provider' => 'App\CustomContextProvider']], $container);

        $this->assertTrue($container->hasAlias(ContextProviderInterface::class));
        $this->assertEquals('App\CustomContextProvider', (string) $container->getAlias(ContextProviderInterface::class));
    }

    private function createContainer(string $environment): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', $environment);

        return $container;
    }
}
