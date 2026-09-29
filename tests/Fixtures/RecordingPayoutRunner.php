<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Fixtures;

use TestHub\Bundle\Action\PayoutRunnerInterface;

final class RecordingPayoutRunner implements PayoutRunnerInterface
{
    /** @var list<array{string, string, int}> */
    public array $calls = [];

    public function payout(string $provider, string $gateway, int $refId): void
    {
        $this->calls[] = [$provider, $gateway, $refId];
    }
}
