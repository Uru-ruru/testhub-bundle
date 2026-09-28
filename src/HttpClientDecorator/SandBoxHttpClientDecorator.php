<?php

declare(strict_types=1);

namespace TestHub\Bundle\HttpClientDecorator;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\HttpClient\SandBoxRequestLog;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use TestHub\Bundle\Service\SandBoxService;

final class SandBoxHttpClientDecorator implements HttpClientInterface
{
    use DecoratorTrait;

    private const string PROXY_HEADER = 'proxy';
    private const string DEPOSIT_TYPE = 'deposit';
    private const string WITHDRAW_TYPE = 'withdraw';
    private const string WITHDRAWAL_TYPE = 'withdrawal';

    /**
     * Types read from the request context. Anything else (e.g. "balance") must be passed in extra.sandboxRequestType.
     */
    private const array BASE_TYPES = [self::DEPOSIT_TYPE, self::WITHDRAW_TYPE, self::WITHDRAWAL_TYPE];

    public function __construct(
        private HttpClientInterface $client,
        /** @var ContextProviderInterface|\Closure(): object any service with a get(): array method */
        private readonly object $contextCollector,
        private readonly SandBoxService $sandboxService,
        private readonly RequestStack $requestStack,
        private readonly ?SandBoxRequestLog $log = null,
    ) {
    }

    /**
     * @param array<mixed> $options
     *
     * @throws TransportExceptionInterface
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // "extra.curl" is the legacy location: it is not a real cURL option and must never reach the transport.
        $type = $options['extra'][SandBoxService::SANDBOX_TYPE] ?? $options['extra']['curl'][SandBoxService::SANDBOX_TYPE] ?? null;
        unset($options['extra'][SandBoxService::SANDBOX_TYPE], $options['extra']['curl'][SandBoxService::SANDBOX_TYPE]);

        if (!$this->sandboxService->isEnabledFor($this->requestStack->getMainRequest())) {
            return $this->passThrough($method, $url, $type, $options);
        }

        $context = $this->getCurrentContext();
        $type ??= $this->getContextType($context);
        $agent = $this->getContextValue($context, 'getAgent');

        // The sandbox cannot route a request without an agent and a type, so it goes to the real API unchanged.
        if (null === $type || null === $agent) {
            return $this->passThrough($method, $url, $type, $options);
        }

        $subagent = $this->getContextValue($context, 'getSubAgent');
        $originalUrl = $this->resolveOriginalUrl($url, $options);
        $sandboxUrl = $this->sandboxService->getUrl().'/'.$agent.'/'.$type.'/'.$this->getEvent($type);
        $options = $this->prepareOptions($options, $originalUrl, $type, $agent, $subagent);

        $response = $this->client->request($method, $sandboxUrl, $options);
        $this->log($method, $originalUrl, $sandboxUrl, $type, $options['headers'][SandBoxService::EVENT_HEADER], true, $options, $response);

        return $response;
    }

    /**
     * @param array<mixed> $options
     *
     * @throws TransportExceptionInterface
     */
    private function passThrough(string $method, string $url, ?string $type, array $options): ResponseInterface
    {
        $response = $this->client->request($method, $url, $options);
        $originalUrl = $this->resolveOriginalUrl($url, $options);
        $this->log($method, $originalUrl, $originalUrl, $type, null, false, $options, $response);

        return $response;
    }

    /**
     * The provider collects one context per outgoing request, so the last one belongs to the request being sent.
     * Any service with a get(): array method works, so applications can keep their own provider interface.
     */
    private function getCurrentContext(): ?object
    {
        $provider = $this->contextCollector instanceof \Closure ? ($this->contextCollector)() : $this->contextCollector;
        $contexts = $provider->get();
        $context = $contexts ? end($contexts) : null;

        return \is_object($context) ? $context : null;
    }

    /**
     * Deposit and withdraw are known from the context: its direction, or its operation when direction is empty.
     */
    private function getContextType(?object $context): ?string
    {
        foreach (['getDirection', 'getOperation'] as $getter) {
            $type = $this->getContextValue($context, $getter);

            if (\in_array($type, self::BASE_TYPES, true)) {
                return $type;
            }
        }

        return null;
    }

    private function getContextValue(?object $context, string $getter): ?string
    {
        if (null === $context || !method_exists($context, $getter)) {
            return null;
        }

        $value = $context->{$getter}();

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Adds the sandbox headers and TLS options, and drops the options that must not reach the transport.
     *
     * @param array<mixed> $options
     *
     * @return array<mixed>
     */
    private function prepareOptions(array $options, string $url, ?string $type, ?string $agent, ?string $subagent): array
    {
        $options['headers'][SandBoxService::URL_HEADER] = $url;
        $options['headers'][SandBoxService::EVENT_HEADER] = $this->getEvent($type);

        // A null value would clear a same-named default header of the decorated client, so only known values are sent.
        foreach ([SandBoxService::AGENT_HEADER => $agent, SandBoxService::SUBAGENT_HEADER => $subagent] as $header => $value) {
            if (null !== $value) {
                $options['headers'][$header] = $value;
            }
        }

        if ('' !== $this->sandboxService->getApiKey()) {
            $options['headers'][SandBoxService::API_KEY_HEADER] = $this->sandboxService->getApiKey();
        }

        unset($options[self::PROXY_HEADER], $options['base_uri']);

        // Only requests rewritten to the sandbox get its TLS options; every other host keeps the normal checks.
        return $this->sandboxService->getTlsOptions() + $options;
    }

    /**
     * @param array<mixed> $options
     */
    private function resolveOriginalUrl(string $url, array $options): string
    {
        $baseUri = $options['base_uri'] ?? null;

        if (!\is_string($baseUri) || '' === $baseUri || preg_match('{^[a-z][a-z\d+.-]*:}i', $url)) {
            return $url;
        }

        return rtrim($baseUri, '/').'/'.ltrim($url, '/');
    }

    private function getEvent(?string $type): string
    {
        $cookie = match ($type) {
            self::DEPOSIT_TYPE,
            SandBoxDataCollector::DEPOSIT_EVENT => SandBoxDataCollector::DEPOSIT_EVENT,
            self::WITHDRAW_TYPE,
            self::WITHDRAWAL_TYPE,
            SandBoxDataCollector::WITHDRAWAL_EVENT => SandBoxDataCollector::WITHDRAWAL_EVENT,
            default => null,
        };

        if (null === $cookie) {
            return SandBoxDataCollector::EVENT_SUCCESS;
        }

        $event = $this->requestStack->getMainRequest()?->cookies->get($cookie);

        return \in_array($event, [SandBoxDataCollector::EVENT_SUCCESS, SandBoxDataCollector::EVENT_FAIL], true)
            ? $event
            : SandBoxDataCollector::EVENT_SUCCESS;
    }

    /**
     * @param array<mixed> $options
     */
    private function log(string $method, string $url, string $targetUrl, ?string $type, ?string $event, bool $sandboxed, array $options, ResponseInterface $response): void
    {
        $this->log?->add([
            'method' => $method,
            'url' => $url,
            'target_url' => $targetUrl,
            'type' => $type,
            'event' => $event,
            'sandboxed' => $sandboxed,
            'options' => array_intersect_key($options, array_flip(['headers', 'query', 'json', 'body', 'auth_bearer'])),
        ], $response);
    }
}
