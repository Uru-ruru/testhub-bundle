<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\Service\SandBoxService;
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

        $request = $collector->getRequests()[0];
        $this->assertSame('https://psp.example/deposit', $request['url']);
        $this->assertSame(200, $request['status_code']);
        $this->assertSame('fail', $request['event']);

        $token = $client->getProfile()->getToken();

        $client->request('GET', '/_profiler/'.$token.'?panel='.SandBoxDataCollector::NAME);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Test Hub sandbox', $client->getResponse()->getContent());
        $this->assertStringContainsString('http://sandbox.test/api/test-agent/deposit/fail', $client->getResponse()->getContent());

        $client->request('GET', '/_wdt/'.$token);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Sandbox on', $client->getResponse()->getContent());
    }
}
