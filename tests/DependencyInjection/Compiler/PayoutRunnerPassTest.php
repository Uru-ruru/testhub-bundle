<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use TestHub\Bundle\Action\PayoutAction;
use TestHub\Bundle\Action\PayoutRunnerInterface;
use TestHub\Bundle\Action\PaySystemsPayoutRunner;
use TestHub\Bundle\DependencyInjection\Compiler\PayoutRunnerPass;
use TestHub\Bundle\DependencyInjection\TestHubExtension;
use TestHub\Bundle\Tests\Fixtures\RecordingPayoutRunner;

class PayoutRunnerPassTest extends TestCase
{
    public function testActionIsRemovedWithoutRunner(): void
    {
        $container = $this->process();

        $this->assertFalse($container->hasDefinition(PayoutAction::class));
    }

    public function testDetectsApplicationRunner(): void
    {
        $container = $this->process(static function (ContainerBuilder $container): void {
            $container->register('app.payout_runner', RecordingPayoutRunner::class)->setAutoconfigured(true);
        });

        $this->assertTrue($container->hasDefinition(PayoutAction::class));
        $this->assertSame('app.payout_runner', (string) $container->getAlias(PayoutRunnerInterface::class));
    }

    public function testApplicationAliasWins(): void
    {
        $container = $this->process(static function (ContainerBuilder $container): void {
            $container->register('app.payout_runner', RecordingPayoutRunner::class)->setAutoconfigured(true);
            $container->register('app.other_runner', RecordingPayoutRunner::class)->setAutoconfigured(true);
            $container->setAlias(PayoutRunnerInterface::class, 'app.other_runner');
        });

        $this->assertSame('app.other_runner', (string) $container->getAlias(PayoutRunnerInterface::class));
    }

    public function testFallsBackToPaySystemsRunner(): void
    {
        $container = $this->process(static function (ContainerBuilder $container): void {
            // Registered by the extension when Xpay\Lib\Systems\PaySystems exists.
            $container->register(PaySystemsPayoutRunner::class);
        });

        $this->assertTrue($container->hasDefinition(PayoutAction::class));
        $this->assertSame(PaySystemsPayoutRunner::class, (string) $container->getAlias(PayoutRunnerInterface::class));
    }

    public function testApplicationRunnerWinsOverPaySystemsRunner(): void
    {
        $container = $this->process(static function (ContainerBuilder $container): void {
            $container->register(PaySystemsPayoutRunner::class);
            $container->register('app.payout_runner', RecordingPayoutRunner::class)->setAutoconfigured(true);
        });

        $this->assertSame('app.payout_runner', (string) $container->getAlias(PayoutRunnerInterface::class));
    }

    public function testSeveralRunnersAreAmbiguous(): void
    {
        $this->expectException(LogicException::class);

        $this->process(static function (ContainerBuilder $container): void {
            $container->register('app.payout_runner', RecordingPayoutRunner::class)->setAutoconfigured(true);
            $container->register('app.other_runner', RecordingPayoutRunner::class)->setAutoconfigured(true);
        });
    }

    private function process(?callable $appServices = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'dev');
        (new TestHubExtension())->load([[]], $container);

        if ($appServices) {
            $appServices($container);
        }

        (new ResolveInstanceofConditionalsPass())->process($container);
        (new PayoutRunnerPass())->process($container);

        return $container;
    }
}
