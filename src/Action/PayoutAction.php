<?php

declare(strict_types=1);

namespace TestHub\Bundle\Action;

/**
 * "Run payout" button: processes the gateway's approved withdrawals the same way the payout cron does.
 * Requests to the payment system go to the sandbox when it is enabled.
 *
 * The processing itself is done by the application's PayoutRunnerInterface.
 */
final class PayoutAction implements ActionInterface
{
    public function __construct(
        private readonly PayoutRunnerInterface $runner,
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return 'payout';
    }

    #[\Override]
    public function getLabel(): string
    {
        return 'Run payout';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Processes approved withdrawals of the gateway, like the payout cron. Only orders that passed security are picked up.';
    }

    #[\Override]
    public function getParameters(): array
    {
        return [
            'provider' => 'Provider (integration code)',
            'gateway' => 'Gateway code (optional)',
            'ref_id' => 'Partner ref_id (optional)',
        ];
    }

    #[\Override]
    public function run(array $parameters): string
    {
        $provider = $parameters['provider'] ?? '';
        $gateway = $parameters['gateway'] ?? '';
        $refId = (int) ($parameters['ref_id'] ?? 0);

        if ('' === $provider) {
            throw new \InvalidArgumentException('Provider is required.');
        }

        $this->runner->payout($provider, $gateway, $refId);

        return \sprintf('Payout finished for %s%s. See the payout log for processed orders.', $provider, '' !== $gateway ? " ({$gateway})" : '');
    }
}
