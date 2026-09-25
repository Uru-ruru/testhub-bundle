<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\Service\SandBoxService;
use TestHub\Bundle\Tests\Functional\App\AgentContextProvider;
use TestHub\Bundle\Tests\Functional\App\TestKernel;

class SandboxProfilerTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        TestKernel::$sentUrls = [];
        TestKernel::$sentHeaders = [];
    }

    public function testRequestsGoToRealApiByDefault(): void
    {
        $client = static::createClient();
        $client->enableProfiler();
        $client->request('GET', '/call-api');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['https://psp.example/deposit'], TestKernel::$sentUrls);

        $collector = $client->getProfile()->getCollector(SandBoxDataCollector::NAME);
        $this->assertFalse($collector->getSandbox());
        $this->assertSame(1, $collector->getRequestCount());
        $this->assertArrayNotHasKey(SandBoxService::API_KEY_HEADER, TestKernel::$sentHeaders[0]);
        $this->assertSame(0, $collector->getSandboxedCount());
    }

    public function testProfilerSwitchSendsRequestsToSandbox(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie(SandBoxService::COOKIE_NAME, '1'));
        $client->getCookieJar()->set(new Cookie(SandBoxDataCollector::DEPOSIT_EVENT, 'fail'));
        $client->enableProfiler();
        $client->request('GET', '/call-api');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['http://sandbox.test/api/test-agent/deposit/fail'], TestKernel::$sentUrls);

        $collector = $client->getProfile()->getCollector(SandBoxDataCollector::NAME);
        $this->assertTrue($collector->getSandbox());
        $this->assertTrue($collector->getOverride());
        $this->assertSame(1, $collector->getSandboxedCount());
        $this->assertSame(AgentContextProvider::class, $collector->getContextProvider());

        $request = $collector->getRequests()[0];
        $this->assertSame('https://psp.example/deposit', $request['url']);
        $this->assertSame(200, $request['status_code']);
        $this->assertSame('fail', $request['event']);
        $this->assertSame(['secret-key'], TestKernel::$sentHeaders[0][SandBoxService::API_KEY_HEADER]);

        $token = $client->getProfile()->getToken();

        $client->request('GET', '/_profiler/'.$token.'?panel='.SandBoxDataCollector::NAME);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Test Hub sandbox', $client->getResponse()->getContent());
        $this->assertStringContainsString('http://sandbox.test/api/test-agent/deposit/fail', $client->getResponse()->getContent());

        $client->request('GET', '/_wdt/'.$token);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Sandbox on', $client->getResponse()->getContent());
    }

    public function testPanelLinksToAjaxRequestsWithHttpCalls(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->enableProfiler();
        $client->request('GET', '/page');
        $pageToken = $client->getProfile()->getToken();

        $client->enableProfiler();
        $client->xmlHttpRequest('POST', '/call-api');
        $ajaxToken = $client->getProfile()->getToken();

        $client->request('GET', '/_profiler/'.$pageToken.'?panel='.SandBoxDataCollector::NAME);
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();

        // The page made no HTTP calls, but its Test Hub menu item must still look active, not disabled.
        $this->assertMatchesRegularExpression('{<li class="test_hub selected">\s*<a [^>]+>\s*<span class="label">}', $content);

        $recent = substr($content, (int) strpos($content, 'id="test-hub-recent"'));
        $recent = substr($recent, 0, (int) strpos($recent, '<h2>Configuration</h2>'));

        $this->assertStringContainsString('/_profiler/'.$ajaxToken.'?panel='.SandBoxDataCollector::NAME, $recent);
        $this->assertStringNotContainsString($pageToken, $recent);
    }

    public function testPanelRendersActionForm(): void
    {
        $client = static::createClient();
        $client->enableProfiler();
        $client->request('GET', '/page');
        $token = $client->getProfile()->getToken();

        $client->request('GET', '/_profiler/'.$token.'?panel='.SandBoxDataCollector::NAME);
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('action="/_test_hub/action/call-api"', $content);
        $this->assertStringContainsString('name="amount"', $content);
        $this->assertStringContainsString('Sends a withdrawal to the PSP.', $content);
    }

    public function testActionRunsWithSandboxCookies(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie(SandBoxService::COOKIE_NAME, '1'));
        $client->getCookieJar()->set(new Cookie(SandBoxDataCollector::WITHDRAWAL_EVENT, 'fail'));
        $client->enableProfiler();
        $client->request('POST', '/_test_hub/action/call-api', ['amount' => '15']);

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['ok' => true, 'message' => 'Sent 15.', 'output' => 'legacy output'],
            json_decode($client->getResponse()->getContent(), true),
        );
        $this->assertSame(['http://sandbox.test/api/test-agent/withdraw/fail'], TestKernel::$sentUrls);
        $this->assertResponseHasHeader('X-Debug-Token-Link');
        $this->assertSame(1, $client->getProfile()->getCollector(SandBoxDataCollector::NAME)->getSandboxedCount());
    }

    public function testActionFailureIsReported(): void
    {
        $client = static::createClient();
        $client->request('POST', '/_test_hub/action/call-api', ['amount' => '']);

        $this->assertResponseStatusCodeSame(500);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['ok']);
        $this->assertSame('InvalidArgumentException: Amount is required.', $data['message']);
        $this->assertSame([], TestKernel::$sentUrls);
    }

    public function testUnknownActionAndGetAreRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/_test_hub/action/nope');
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/_test_hub/action/call-api');
        $this->assertResponseStatusCodeSame(405);
    }
}
