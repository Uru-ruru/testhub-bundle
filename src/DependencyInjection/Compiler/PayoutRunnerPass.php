<?php

declare(strict_types=1);

namespace TestHub\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use TestHub\Bundle\Action\PayoutAction;
use TestHub\Bundle\Action\PayoutRunnerInterface;

/**
 * Wires PayoutAction to the application's PayoutRunnerInterface: an alias defined by the application,
 * or its only autoconfigured implementation. Without one, PayoutAction is removed.
 */
final class PayoutRunnerPass implements CompilerPassInterface
{
    public const string TAG = 'test_hub.payout_runner';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PayoutAction::class) || $container->has(PayoutRunnerInterface::class)) {
            return;
        }

        $candidates = array_values(array_filter(
            array_keys($container->findTaggedServiceIds(self::TAG)),
            static fn (string $id) => !$container->getDefinition($id)->isAbstract(),
        ));

        if (\count($candidates) > 1) {
            throw new LogicException(\sprintf('Several services implement "%s" (%s). Choose one by aliasing the interface.', PayoutRunnerInterface::class, implode(', ', $candidates)));
        }

        if (!$candidates) {
            $container->removeDefinition(PayoutAction::class);

            return;
        }

        $container->setAlias(PayoutRunnerInterface::class, $candidates[0]);
    }
}
