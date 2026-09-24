<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Functional\App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TestHub\Bundle\TestHubBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var list<string> URLs that reached the transport, across kernel reboots */
    public static array $sentUrls = [];

    /** @var list<array<string, list<string>>> */
    public static array $sentHeaders = [];

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new WebProfilerBundle();
        yield new TestHubBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/testhub-bundle/'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/testhub-bundle/'.$this->environment.'/log';
    }

    /**
     * @param array<mixed> $options
     */
    public static function mockResponse(string $method, string $url, array $options): MockResponse
    {
        self::$sentUrls[] = $url;
        self::$sentHeaders[] = array_map(
            static fn (array $lines) => array_map(static fn (string $line) => substr($line, strpos($line, ':') + 2), $lines),
            $options['normalized_headers'],
        );

        return new MockResponse('{"status":"ok"}', ['response_headers' => ['content-type' => 'application/json']]);
    }

    public function callApi(HttpClientInterface $httpClient): JsonResponse
    {
        $response = $httpClient->request('POST', 'https://psp.example/deposit', [
            'json' => ['amount' => 10],
            'extra' => ['sandboxRequestType' => 'deposit'],
        ]);

        return new JsonResponse(['upstream' => $response->toArray()]);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'router' => ['utf8' => true],
            'profiler' => ['enabled' => true, 'collect' => true],
            'http_client' => ['mock_response_factory' => 'test.mock_response_factory'],
        ]);
        $container->extension('web_profiler', ['toolbar' => true]);
        $container->extension('test_hub', [
            'environments' => ['test'],
            'sandbox_url' => 'http://sandbox.test',
            'use_sandbox' => false,
            'api_key' => 'secret-key',
        ]);

        $container->services()
            ->set('test.mock_response_factory', \Closure::class)
                ->factory([\Closure::class, 'fromCallable'])
                ->args([[self::class, 'mockResponse']])
            // The application's own implementation: the bundle must pick it up without configuration.
            ->set(AgentContextProvider::class)->autoconfigure()
            ->set('logger', \Psr\Log\NullLogger::class)
            ->set('kernel', self::class)->synthetic()->public()->autowire()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')->prefix('/_wdt');
        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
        $routes->add('call_api', '/call-api')->controller('kernel::callApi');
    }
}
