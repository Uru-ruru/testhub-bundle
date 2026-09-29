<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Action;

use PHPUnit\Framework\TestCase;
use TestHub\Bundle\Action\PayoutAction;
use TestHub\Bundle\Tests\Fixtures\RecordingPayoutRunner;

class PayoutActionTest extends TestCase
{
    public function testRunsPayout(): void
    {
        $runner = new RecordingPayoutRunner();

        $result = (new PayoutAction($runner))->run(['provider' => 'testpay', 'gateway' => 'card', 'ref_id' => '7']);

        $this->assertSame([['testpay', 'card', 7]], $runner->calls);
        $this->assertStringContainsString('testpay (card)', $result);
    }

    public function testOptionalParametersDefault(): void
    {
        $runner = new RecordingPayoutRunner();

        (new PayoutAction($runner))->run(['provider' => 'testpay']);

        $this->assertSame([['testpay', '', 0]], $runner->calls);
    }

    public function testProviderIsRequired(): void
    {
        $runner = new RecordingPayoutRunner();

        $this->expectException(\InvalidArgumentException::class);

        try {
            (new PayoutAction($runner))->run(['provider' => '']);
        } finally {
            $this->assertSame([], $runner->calls);
        }
    }
}
