<?php

namespace TestHub\Bundle\Collector;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use TestHub\Bundle\HttpClient\SandBoxRequestLog;
use TestHub\Bundle\Service\SandBoxService;

class SandBoxDataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    public const string NAME = 'test_hub';

    public const string DEPOSIT_EVENT = 'deposit_event';
    public const string WITHDRAWAL_EVENT = 'withdrawal_event';

    public const string EVENT_SUCCESS = 'success';
    public const string EVENT_FAIL = 'fail';

    private const array EVENT_VARIANTS = [
        self::EVENT_SUCCESS,
        self::EVENT_FAIL,
    ];

    private const int MAX_BODY_LENGTH = 65536;

    public function __construct(
        private readonly SandBoxService $sandboxService,
        private readonly ?SandBoxRequestLog $requestLog = null,
        private readonly string $contextProvider = '',
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data['sandbox'] = $this->sandboxService->isEnabledFor($request);
        $this->data['default'] = $this->sandboxService->getStatus();
        $this->data['override'] = $this->sandboxService->getOverride($request);
        $this->data['configured'] = $this->sandboxService->isConfigured();
        $this->data['tests'] = false;
        $this->data['apikey'] = $this->sandboxService->getApiKey();
        $this->data['url'] = $this->sandboxService->getUrl();
        $this->data['url_wrap'] = $this->sandboxService->getUrlWrap();
        $this->data['context_provider'] = $this->contextProvider;
        $this->data['time'] = microtime(true);
        $this->data['requests'] = [];
        $this->data[self::DEPOSIT_EVENT] = null;
        $this->data[self::WITHDRAWAL_EVENT] = null;

        foreach ([self::DEPOSIT_EVENT, self::WITHDRAWAL_EVENT] as $event) {
            $value = $request->cookies->get($event);

            if (\in_array($value, self::EVENT_VARIANTS, true)) {
                $this->data[$event] = $value;
            }
        }
    }

    /**
     * Runs on kernel.terminate, once the outgoing responses have had a chance to complete.
     */
    public function lateCollect(): void
    {
        $requests = [];

        foreach ($this->requestLog?->all() ?? [] as ['request' => $request, 'response' => $response]) {
            $info = $response->getInfo();

            $requests[] = $this->cloneRequest($request + [
                'status_code' => (int) ($info['http_code'] ?? 0),
                'duration' => isset($info['total_time']) ? $info['total_time'] * 1000 : null,
                'error' => $info['error'] ?? null,
                'response_headers' => $info['response_headers'] ?? [],
                'response_body' => $request['sandboxed'] ? $this->readBody($response) : null,
            ]);
        }

        $this->data['requests'] = $requests;
        $this->requestLog?->reset();
    }

    public function reset(): void
    {
        parent::reset();
        $this->requestLog?->reset();
    }

    public function getSandbox(): bool
    {
        return (bool) $this->data['sandbox'];
    }

    public function getDefault(): bool
    {
        return (bool) ($this->data['default'] ?? false);
    }

    public function getOverride(): ?bool
    {
        return $this->data['override'] ?? null;
    }

    public function getConfigured(): bool
    {
        return (bool) ($this->data['configured'] ?? false);
    }

    public function getTests(): bool
    {
        return (bool) $this->data['tests'];
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

    public function getUrlWrap(): string
    {
        return $this->data['url_wrap'] ?? '';
    }

    public function getContextProvider(): string
    {
        return $this->data['context_provider'] ?? '';
    }

    public function getDepositEvent(): ?string
    {
        return $this->data[self::DEPOSIT_EVENT];
    }

    public function getWithdrawalEvent(): ?string
    {
        return $this->data[self::WITHDRAWAL_EVENT];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRequests(): array
    {
        return $this->data['requests'] ?? [];
    }

    public function getRequestCount(): int
    {
        return \count($this->getRequests());
    }

    public function getSandboxedCount(): int
    {
        return \count(array_filter($this->getRequests(), static fn (array $r) => $r['sandboxed']));
    }

    public function getErrorCount(): int
    {
        return \count(array_filter($this->getRequests(), static fn (array $r) => $r['error'] || $r['status_code'] >= 400));
    }

    public static function getTemplate(): ?string
    {
        return '@TestHub/profiler/sandbox_collector.html.twig';
    }

    public function getName(): string
    {
        return self::NAME;
    }

    private function readBody(ResponseInterface $response): ?string
    {
        try {
            $body = $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }

        return \strlen($body) > self::MAX_BODY_LENGTH ? substr($body, 0, self::MAX_BODY_LENGTH).'…' : $body;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function cloneRequest(array $request): array
    {
        $request['options'] = $this->cloneVar($request['options']);
        $request['response_headers'] = $this->cloneVar($request['response_headers']);

        if (null !== $request['response_body']) {
            $decoded = json_decode($request['response_body'], true);
            $request['response_body'] = \JSON_ERROR_NONE === json_last_error() && \is_array($decoded)
                ? $this->cloneVar($decoded)
                : $request['response_body'];
        }

        return $request;
    }
}
