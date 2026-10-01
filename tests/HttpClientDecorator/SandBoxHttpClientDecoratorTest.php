<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\HttpClientDecorator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use TestHub\Bundle\HttpClient\SandBoxRequestLog;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\HttpClientDecorator\SandBoxHttpClientDecorator;
use TestHub\Bundle\Service\SandBoxService;
use TestHub\Bundle\Tests\Fixtures\AppContext;
use TestHub\Bundle\Tests\Fixtures\AppContextCollector;

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
        $this->assertContains(SandBoxService::URL_HEADER.': https://real-api.test/pay', $this->sent[0]['options']['headers']);
        $this->assertContains(SandBoxService::EVENT_HEADER.': fail', $this->sent[0]['options']['headers']);
        $this->assertContains(SandBoxService::API_KEY_HEADER.': key', $this->sent[0]['options']['headers']);
    }

    public function testApiKeyIsNotSentToRealApi(): void
    {
        $this->createDecorator(useSandbox: false)->request('GET', 'https://real-api.test/data');

        $this->assertArrayNotHasKey(SandBoxService::API_KEY_HEADER, $this->sent[0]['options']['normalized_headers']);
    }

    public function testEmptyApiKeyIsNotSent(): void
    {
        $this->createDecorator(useSandbox: true, apiKey: '')->request('GET', 'https://real-api.test/data');

        $this->assertArrayNotHasKey(SandBoxService::API_KEY_HEADER, $this->sent[0]['options']['normalized_headers']);
    }

    public function testTypeCanBePassedInExtra(): void
    {
        $this->createDecorator(useSandbox: true)->request('POST', 'https://real-api.test/payout', [
            'extra' => [SandBoxService::SANDBOX_TYPE => 'withdrawal'],
        ]);

        $this->assertSame('http://sandbox.test/api/test-agent/withdrawal/success', $this->sent[0]['url']);
        $this->assertArrayNotHasKey(SandBoxService::SANDBOX_TYPE, $this->sent[0]['options']['extra']);
    }

    public function testAgentOfTheCurrentRequestIsUsed(): void
    {
        $collector = new AppContextCollector();
        $decorator = $this->createDecorator(useSandbox: true, contextProvider: $collector);

        // Outer decorators collect the context right before this decorator runs.
        $collector->collect(new AppContext('https://first.test', 'first-agent'));
        $decorator->request('POST', 'https://first.test/pay', ['extra' => [SandBoxService::SANDBOX_TYPE => 'deposit']]);
        $collector->collect(new AppContext('https://second.test', 'second-agent'));
        $decorator->request('POST', 'https://second.test/pay', ['extra' => [SandBoxService::SANDBOX_TYPE => 'deposit']]);

        $this->assertSame('http://sandbox.test/api/first-agent/deposit/success', $this->sent[0]['url']);
        $this->assertSame('http://sandbox.test/api/second-agent/deposit/success', $this->sent[1]['url']);
    }

    public function testDepositTypeIsTakenFromContextDirection(): void
    {
        $this->pushRequest(['deposit_event' => 'fail']);

        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: 'deposit'));

        $this->assertSame('http://sandbox.test/api/nexumpay/deposit/fail', $this->sent[0]['url']);
        $this->assertSame('deposit', $this->log->all()[0]['request']['type']);
    }

    public function testWithdrawTypeFallsBackToContextOperation(): void
    {
        $this->pushRequest(['withdrawal_event' => 'fail']);

        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: '', operation: 'withdraw'));

        $this->assertSame('http://sandbox.test/api/nexumpay/withdraw/fail', $this->sent[0]['url']);
    }

    public function testPendingAndErrorEventsAreSent(): void
    {
        $this->pushRequest(['deposit_event' => 'pending', 'withdrawal_event' => 'error']);

        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: 'deposit'));
        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: 'withdraw'));

        $this->assertSame('http://sandbox.test/api/nexumpay/deposit/pending', $this->sent[0]['url']);
        $this->assertSame('http://sandbox.test/api/nexumpay/withdraw/error', $this->sent[1]['url']);
    }

    public function testUnknownEventFallsBackToSuccess(): void
    {
        $this->pushRequest(['deposit_event' => 'refunded']);

        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: 'deposit'));

        $this->assertSame('http://sandbox.test/api/nexumpay/deposit/success', $this->sent[0]['url']);
    }

    public function testExplicitTypeOverridesContext(): void
    {
        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: 'deposit'), [
            'extra' => [SandBoxService::SANDBOX_TYPE => 'balance'],
        ]);

        $this->assertSame('http://sandbox.test/api/nexumpay/balance/success', $this->sent[0]['url']);
    }

    public function testUnknownContextDirectionIsIgnored(): void
    {
        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: 'unknown', operation: 'status'));

        $this->assertSame('https://psp.test/pay', $this->sent[0]['url']);
        $this->assertFalse($this->log->all()[0]['request']['sandboxed']);
    }

    public function testAgentAndSubAgentAreSentAsHeaders(): void
    {
        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: 'deposit', subagent: 'nexumpay-eu'));

        $this->assertContains(SandBoxService::AGENT_HEADER.': nexumpay', $this->sent[0]['options']['headers']);
        $this->assertContains(SandBoxService::SUBAGENT_HEADER.': nexumpay-eu', $this->sent[0]['options']['headers']);
    }

    public function testUnknownAgentAndSubAgentAreNotSentAsHeaders(): void
    {
        // A null header value clears a same-named default header of the decorated client, so nothing is set.
        $this->sendWithContext(new AppContext('https://psp.test', null));

        $this->assertArrayNotHasKey(SandBoxService::AGENT_HEADER, $this->sent[0]['options']['normalized_headers']);
        $this->assertArrayNotHasKey(SandBoxService::SUBAGENT_HEADER, $this->sent[0]['options']['normalized_headers']);
    }

    public function testRequestWithoutAgentPassesThrough(): void
    {
        $collector = new AppContextCollector();
        $collector->collect(new AppContext('https://real-api.test', null));

        $this->createDecorator(useSandbox: true, contextProvider: $collector)->request('POST', 'https://real-api.test/pay', [
            'extra' => [SandBoxService::SANDBOX_TYPE => 'deposit'],
        ]);

        $this->assertSame('https://real-api.test/pay', $this->sent[0]['url']);
    }

    public function testProviderIsResolvedLazily(): void
    {
        $built = false;
        $decorator = $this->createDecorator(useSandbox: false, contextProvider: static function () use (&$built): AppContextCollector {
            $built = true;

            return new AppContextCollector();
        });

        $decorator->request('GET', 'https://real-api.test/data');

        $this->assertFalse($built);
    }

    public function testUntypedRequestPassesThrough(): void
    {
        // e.g. an auth call to an internal service while the sandbox is on
        $this->createDecorator(useSandbox: true)->request('POST', 'oauth/token', ['base_uri' => 'https://auth.test/v1/']);

        $this->assertSame('https://auth.test/v1/oauth/token', $this->sent[0]['url']);
        $this->assertArrayNotHasKey(SandBoxService::URL_HEADER, $this->sent[0]['options']['normalized_headers']);
        $this->assertArrayNotHasKey(SandBoxService::API_KEY_HEADER, $this->sent[0]['options']['normalized_headers']);
        $this->assertSame('https://auth.test/v1/oauth/token', $this->log->all()[0]['request']['url']);
        $this->assertFalse($this->log->all()[0]['request']['sandboxed']);
    }

    #[DataProvider('provideBaseUriReferences')]
    public function testLoggedUrlMatchesTheUrlHttpClientCalls(string $url): void
    {
        $this->createDecorator(useSandbox: false)->request('GET', $url, ['base_uri' => 'https://real-api.test/v1/']);

        $this->assertSame($this->sent[0]['url'], $this->log->all()[0]['request']['url']);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideBaseUriReferences(): iterable
    {
        yield 'relative' => ['data'];
        yield 'absolute path' => ['/oauth/token'];
        yield 'parent' => ['../v2/data'];
        yield 'query' => ['data?page=2'];
    }

    public function testAbsolutePathReplacesBaseUriPathInUrlHeader(): void
    {
        $this->createDecorator(useSandbox: true)->request('GET', '/balance', [
            'base_uri' => 'https://real-api.test/v1/',
            'extra' => [SandBoxService::SANDBOX_TYPE => 'balance'],
        ]);

        $this->assertContains(SandBoxService::URL_HEADER.': https://real-api.test/balance', $this->sent[0]['options']['headers']);
    }

    public function testContextOfAnotherHostIsIgnored(): void
    {
        $collector = new AppContextCollector();
        $decorator = $this->createDecorator(useSandbox: true, contextProvider: $collector);

        $collector->collect(new AppContext('https://psp.test', 'nexumpay', direction: 'deposit'));
        $decorator->request('POST', 'https://psp.test/pay');
        // The auth call collects no context of its own, so the provider still returns the deposit one.
        $decorator->request('POST', 'https://auth.test/oauth/token');

        $this->assertSame('http://sandbox.test/api/nexumpay/deposit/success', $this->sent[0]['url']);
        $this->assertSame('https://auth.test/oauth/token', $this->sent[1]['url']);
    }

    public function testBaseUriIsResolvedIntoUrlHeader(): void
    {
        $this->createDecorator(useSandbox: true)->request('GET', 'data', [
            'base_uri' => 'https://real-api.test/v1/',
            'extra' => [SandBoxService::SANDBOX_TYPE => 'balance'],
        ]);

        $this->assertSame('http://sandbox.test/api/test-agent/balance/success', $this->sent[0]['url']);
        $this->assertContains(SandBoxService::URL_HEADER.': https://real-api.test/v1/data', $this->sent[0]['options']['headers']);
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
        $options = ['extra' => [SandBoxService::SANDBOX_TYPE => 'balance']];

        $this->pushRequest([SandBoxService::COOKIE_NAME => '1']);
        $this->createDecorator(useSandbox: false)->request('GET', 'https://real-api.test/data', $options);
        $this->assertSame('http://sandbox.test/api/test-agent/balance/success', $this->sent[0]['url']);

        $this->sent = [];
        $this->requestStack->pop();
        $this->pushRequest([SandBoxService::COOKIE_NAME => '0']);
        $this->createDecorator(useSandbox: true)->request('GET', 'https://real-api.test/data', $options);
        $this->assertSame('https://real-api.test/data', $this->sent[0]['url']);
    }

    public function testSandboxStaysOffWithoutUrl(): void
    {
        $this->pushRequest([SandBoxService::COOKIE_NAME => '1']);
        $this->createDecorator(useSandbox: true, sandboxUrl: '')->request('GET', 'https://real-api.test/data');

        $this->assertSame('https://real-api.test/data', $this->sent[0]['url']);
    }

    public function testSandboxTlsOptionsAreAppliedToSandboxRequests(): void
    {
        $this->sendWithContext(new AppContext('https://psp.test', 'nexumpay', direction: 'deposit'), [
            'verify_peer' => true,
        ], new SandBoxService('key', true, 'http://sandbox.test', '/certs/sandbox.pem', false));

        $this->assertSame('http://sandbox.test/api/nexumpay/deposit/success', $this->sent[0]['url']);
        $this->assertSame('/certs/sandbox.pem', $this->sent[0]['options']['cafile']);
        $this->assertFalse($this->sent[0]['options']['verify_peer']);
        $this->assertFalse($this->sent[0]['options']['verify_host']);
    }

    public function testSandboxTlsOptionsAreNotAppliedToOtherHosts(): void
    {
        $this->createDecorator(useSandbox: false, sandboxService: new SandBoxService('key', false, 'http://sandbox.test', '/certs/sandbox.pem', false))
            ->request('GET', 'https://real-api.test/data');

        $this->assertSame('https://real-api.test/data', $this->sent[0]['url']);
        foreach (['cafile', 'verify_peer', 'verify_host'] as $option) {
            $this->assertArrayNotHasKey($option, $this->sent[0]['options']);
        }
    }

    public function testRequestsAreLogged(): void
    {
        $this->createDecorator(useSandbox: true)->request('GET', 'https://real-api.test/data', [
            'extra' => [SandBoxService::SANDBOX_TYPE => 'balance'],
        ]);

        $entry = $this->log->all()[0]['request'];
        $this->assertTrue($entry['sandboxed']);
        $this->assertSame('https://real-api.test/data', $entry['url']);
        $this->assertSame('http://sandbox.test/api/test-agent/balance/success', $entry['target_url']);
    }

    /**
     * @param array<mixed> $options
     */
    private function sendWithContext(AppContext $context, array $options = [], ?SandBoxService $sandboxService = null): void
    {
        $collector = new AppContextCollector();
        $collector->collect($context);

        $this->createDecorator(useSandbox: true, contextProvider: $collector, sandboxService: $sandboxService)->request('POST', 'https://psp.test/pay', $options);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function pushRequest(array $cookies): void
    {
        $this->requestStack->push(new Request(cookies: $cookies));
    }

    private function createDecorator(bool $useSandbox, string $sandboxUrl = 'http://sandbox.test', string $apiKey = 'key', ?object $contextProvider = null, ?SandBoxService $sandboxService = null): SandBoxHttpClientDecorator
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{}');
        });

        $contextProvider ??= new class implements ContextProviderInterface {
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
            $sandboxService ?? new SandBoxService($apiKey, $useSandbox, $sandboxUrl),
            $this->requestStack,
            $this->log,
        );
    }
}
