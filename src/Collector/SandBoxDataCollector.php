<?php

namespace TestHub\Bundle\Collector;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use TestHub\Bundle\Service\SandBoxService;

class SandBoxDataCollector extends AbstractDataCollector
{
    public const string DEPOSIT_EVENT = 'deposit_event';
    public const string WITHDRAWAL_EVENT = 'withdrawal_event';

    public const string EVENT_SUCCESS = 'success';
    public const string EVENT_FAIL = 'fail';

    private const array EVENT_VARIANTS = [
        self::EVENT_SUCCESS,
        self::EVENT_FAIL,
    ];

    public function __construct(
        private readonly SandBoxService $sandboxService,
    )
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data['sandbox'] = $this->sandboxService->getStatus();
        $this->data['tests'] = false;
        $this->data['apikey'] = $this->sandboxService->getApiKey();
        $this->data['url'] = $this->sandboxService->getUrl();
        $this->data['time'] = microtime(true);
        $this->data[self::DEPOSIT_EVENT] = null;
        $this->data[self::WITHDRAWAL_EVENT] = null;

        foreach ([self::DEPOSIT_EVENT, self::WITHDRAWAL_EVENT] as $event) {
            $value = $request->cookies->get($event);

            if (\in_array($value, self::EVENT_VARIANTS, true)) {
                $this->data[$event] = $value;
            }
        }
    }

    public function getSandbox(): bool
    {
        return (bool)$this->data['sandbox'];
    }

    public function getTests(): bool
    {
        return (bool)$this->data['tests'];
    }

    public function getTime(): float
    {
        return $this->data['time'];
    }

    public function getApiKey(): string
    {
        return $this->data['apikey'];
    }

    public function getUrl(): string
    {
        return $this->data['url'];
    }

    public function getDepositEvent(): ?string
    {
        return $this->data[self::DEPOSIT_EVENT];
    }

    public function getWithdrawalEvent(): ?string
    {
        return $this->data[self::WITHDRAWAL_EVENT];
    }

    public static function getTemplate(): ?string
    {
        return 'profiler/sandbox_collector.html.twig';
    }

    public function getName(): string
    {
        return 'app.sandbox_collector';
    }
}
