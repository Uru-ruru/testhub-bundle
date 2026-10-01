<?php

declare(strict_types=1);

namespace TestHub\Bundle\Action;

use Xpay\Lib\System\PaySystemConfiguration;
use Xpay\Lib\Systems\PaySystems;

/**
 * Built-in runner for the 1xpay payment systems: runs Gateway::payout() the same way the payout cron does.
 *
 * Registered only when Xpay\Lib\Systems\PaySystems exists, and used only when the application
 * has no PayoutRunnerInterface of its own.
 */
final class PaySystemsPayoutRunner implements PayoutRunnerInterface
{
    #[\Override]
    public function payout(string $provider, string $gateway, int $refId): void
    {
        PaySystems::reset();

        $system = PaySystems::initSystem($provider, $gateway, config: new PaySystemConfiguration(PaySystems::TYPE_OP_PAYMENTS));
        $system->ref_id = $refId;
        $system->direction = PaySystems::DIRECTION_WITHDRAW;
        $system->payout();
    }
}
