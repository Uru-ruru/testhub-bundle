<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\HttpClientDecorator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use TestHub\Bundle\HttpClient\SandBoxRequestLog;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClientDecorator\SandBoxHttpClientDecorator;
use TestHub\Bundle\Service\SandBoxService;

class SandBoxHttpClientDecoratorTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<mixed>}> */
    private array $sent = [];
    private RequestStack $requestStack;
    private SandBoxRequestLog $log;

    protected function setUp(): void
    {
        $this->sent = [];
        $this->requestStack = new RequestStack();
        $this->log = new SandBoxRequestLog();
    }

    public function testDepositRequestIsSentToAgentEndpoint(): void
    {
        $this->pushRequest(['deposit_event' => 'fail']);

        $this->createDecorator(useSandbox: true)->request('POST', 'https://real-api.test/pay', [
            'extra' => ['curl' => [SandBoxService::SANDBOX_TYPE => 'deposit']],
        ]);

        $this->assertSame('http://sandbox.test/api/test-agent/deposit/fail', $this->sent[0]['url']);
        $this->assertContains('url: https://real-api.test/pay', $this->sent[0]['options']['headers']);
        $this->assertContains('event: fail', $this->sent[0]['options']['headers']);
        $this->assertContains('api-key: key', $this->sent[0]['options']['headers']);
    }

    public function testApiKeyIsNotSentToRealApi(): void
    {
        $this->createDecorator(useSandbox: false)->request('GET', 'https://real-api.test/data');

        $this->assertArrayNotHasKey('api-key', $this->sent[0]['options']['normalized_headers']);
    }

    public function testEmptyApiKeyIsNotSent(): void
    {
        $this->createDecorator(useSandbox: true, apiKey: '')->request('GET', 'https://real-api.test/data');

        $this->assertArrayNotHasKey('api-key', $this->sent[0]['options']['normalized_headers']);
    }

    public function testTypeCanBePassedInExtra(): void
    {
        $this->createDecorator(useSandbox: true)->request('POST', 'https://real-api.test/payout', [
            'extra' => [SandBoxService::SANDBOX_TYPE => 'withdrawal'],
        ]);

        $this->assertSame('http://sandbox.test/api/test-agent/withdrawal/success', $this->sent[0]['url']);
        $this->assertArrayNotHasKey(SandBoxService::SANDBOX_TYPE, $this->sent[0]['options']['extra']);
    }

    public function testUntypedRequestIsSentToWrapEndpoint(): void
    {
        $this->createDecorator(useSandbox: true)->request('GET', '/data', ['base_uri' => 'https://real-api.test/v1/']);

        $this->assertSame('http://sandbox.test/api_wrap', $this->sent[0]['url']);
        $this->assertContains('url: https://real-api.test/v1/data', $this->sent[0]['options']['headers']);
    }

    public function testRequestPassesThroughWhenSandboxIsOff(): void
    {
        $this->createDecorator(useSandbox: false)->request('GET', 'https://real-api.test/data', [
            'extra' => ['curl' => [SandBoxService::SANDBOX_TYPE => 'deposit']],
        ]);

        $this->assertSame('https://real-api.test/data', $this->sent[0]['url']);
        $this->assertArrayNotHasKey(SandBoxService::SANDBOX_TYPE, $this->sent[0]['options']['extra']['curl'] ?? []);
        $this->assertFalse($this->log->all()[0]['request']['sandboxed']);
    }

    public function testProfilerCookieOverridesConfigDefault(): void
    {
        $this->pushRequest([SandBoxService::COOKIE_NAME => '1']);
        $this->createDecorator(useSandbox: false)->request('GET', 'https://real-api.test/data');
        $this->assertSame('http://sandbox.test/api_wrap', $this->sent[0]['url']);

        $this->sent = [];
        $this->requestStack->pop();
        $this->pushRequest([SandBoxService::COOKIE_NAME => '0']);
        $this->createDecorator(useSandbox: true)->request('GET', 'https://real-api.test/data');
        $this->assertSame('https://real-api.test/data', $this->sent[0]['url']);
    }

    public function testSandboxStaysOffWithoutUrl(): void
    {
        $this->pushRequest([SandBoxService::COOKIE_NAME => '1']);
        $this->createDecorator(useSandbox: true, sandboxUrl: '')->request('GET', 'https://real-api.test/data');

        $this->assertSame('https://real-api.test/data', $this->sent[0]['url']);
    }

    public function testRequestsAreLogged(): void
    {
        $this->createDecorator(useSandbox: true)->request('GET', 'https://real-api.test/data');

        $entry = $this->log->all()[0]['request'];
        $this->assertTrue($entry['sandboxed']);
        $this->assertSame('https://real-api.test/data', $entry['url']);
        $this->assertSame('http://sandbox.test/api_wrap', $entry['target_url']);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function pushRequest(array $cookies): void
    {
        $this->requestStack->push(new Request(cookies: $cookies));
    }

    private function createDecorator(bool $useSandbox, string $sandboxUrl = 'http://sandbox.test', string $apiKey = 'key'): SandBoxHttpClientDecorator
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{}');
        });

        $contextProvider = new class implements ContextProviderInterface {
            public function get(): array
            {
                return [new class {
                    public function getAgent(): string
                    {
                        return 'test-agent';
                    }
                }];
            }
        };

        return new SandBoxHttpClientDecorator(
            $client,
            $contextProvider,
            new SandBoxService($apiKey, $useSandbox, $sandboxUrl),
            $this->requestStack,
            $this->log,
        );
    }
}
