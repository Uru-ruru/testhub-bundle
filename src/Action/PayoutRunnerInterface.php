<?php

declare(strict_types=1);

namespace TestHub\Bundle\Action;

/**
 * Runs the application's payout processing for PayoutAction, the same way the payout cron does.
 *
 * Implement it in the application. With autoconfiguration on, the "Run payout" button appears in the panel;
 * without an implementation the button is not registered.
 */
interface PayoutRunnerInterface
{
    /**
     * @param string $provider integration code of the payment system
     * @param string $gateway  gateway code, or "" for the provider's default
     * @param int    $refId    partner ref_id, or 0 for none
     *
     * @throws \Throwable to report a failure
     */
    public function payout(string $provider, string $gateway, int $refId): void;
}
