<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\HttpClientDecorator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClientDecorator\SandBoxHttpClientDecorator;
use TestHub\Bundle\Service\SandBoxService;

class SandBoxHttpClientDecoratorTest extends TestCase
{
    public function testRequestUsesContextProvider(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $contextProvider = $this->createMock(ContextProviderInterface::class);
        $sandboxService = $this->createMock(SandBoxService::class);
        $requestStack = new RequestStack();

        $agent = new class {
            public function getAgent(): string
            {
                return 'test-agent';
            }
        };

        $contextProvider->expects($this->once())
            ->method('get')
            ->willReturn([$agent]);

        $sandboxService->method('getUrl')->willReturn('http://sandbox.test/api');

        $decorator = new SandBoxHttpClientDecorator(
            $client,
            $contextProvider,
            $sandboxService,
            $requestStack
        );

        $client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains('test-agent'),
                $this->isType('array')
            )
            ->willReturn($this->createMock(ResponseInterface::class));

        $decorator->request('GET', 'http://real-api.test/data', [
            'extra' => ['curl' => [SandBoxService::SANDBOX_TYPE => 'deposit']]
        ]);
    }
}
