<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TestHub\Bundle\DependencyInjection\TestHubExtension;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClient\State\DefaultContextProvider;
use TestHub\Bundle\HttpClientDecorator\SandBoxHttpClientDecorator;
use TestHub\Bundle\Service\SandBoxService;

class TestHubExtensionTest extends TestCase
{
    private TestHubExtension $extension;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new TestHubExtension();
        $this->container = new ContainerBuilder();
        
        // Mock required external services for the container
        $this->container->register('request_stack', RequestStack::class);
        $this->container->register('http_client.transport', HttpClientInterface::class);
    }

    public function testLoadDefaultConfiguration(): void
    {
        $this->extension->load([], $this->container);

        $this->assertTrue($this->container->has(SandBoxService::class));
        $this->assertTrue($this->container->has(SandBoxHttpClientDecorator::class));
        
        // Check alias for ContextProviderInterface
        $this->assertTrue($this->container->hasAlias(ContextProviderInterface::class));
        $this->assertEquals(DefaultContextProvider::class, (string) $this->container->getAlias(ContextProviderInterface::class));
        
        // Check if DefaultContextProvider is registered because it's the default
        $this->assertTrue($this->container->has(DefaultContextProvider::class));

        // Check SandBoxService arguments
        $definition = $this->container->getDefinition(SandBoxService::class);
        $this->assertEquals('%env(USE_SANDBOX)%', $definition->getArgument('$useSandbox'));
        $this->assertEquals('%env(SANDBOX_URL)%', $definition->getArgument('$sandboxUrl'));
    }

    public function testLoadWithCustomContextProvider(): void
    {
        $configs = [
            'test_hub' => [
                'context_provider' => 'App\CustomContextProvider',
            ],
        ];

        $this->extension->load($configs, $this->container);

        $this->assertTrue($this->container->hasAlias(ContextProviderInterface::class));
        $this->assertEquals('App\CustomContextProvider', (string) $this->container->getAlias(ContextProviderInterface::class));
    }
}
