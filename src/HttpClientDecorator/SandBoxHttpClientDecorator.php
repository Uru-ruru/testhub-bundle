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
    private const string WITHDRAWAL_TYPE = 'withdrawal';

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
            $response = $this->client->request($method, $url, $options);
            $this->log($method, $url, $url, $type, null, false, $options, $response);

            return $response;
        }

        $originalUrl = $this->resolveOriginalUrl($url, $options);
        $sandboxUrl = $this->setSandBoxRequestUrl($type);
        $options = $this->resetOptions($options, $originalUrl, $type);

        $response = $this->client->request($method, $sandboxUrl, $options);
        $this->log($method, $originalUrl, $sandboxUrl, $type, $options['headers'][SandBoxService::EVENT_HEADER], true, $options, $response);

        return $response;
    }

    private function setSandBoxRequestUrl(?string $type): string
    {
        $agent = $this->getAgent();

        if (null === $type || null === $agent) {
            return $this->sandboxService->getUrlWrap();
        }

        return $this->sandboxService->getUrl().'/'.$agent.'/'.$type.'/'.$this->getEvent($type);
    }

    /**
     * The provider collects one context per outgoing request, so the last one belongs to the request being sent.
     * Any service with a get(): array method works, so applications can keep their own provider interface.
     */
    private function getAgent(): ?string
    {
        $provider = $this->contextCollector instanceof \Closure ? ($this->contextCollector)() : $this->contextCollector;
        $contexts = $provider->get();
        $context = $contexts ? end($contexts) : null;

        if (!\is_object($context) || !method_exists($context, 'getAgent')) {
            return null;
        }

        $agent = $context->getAgent();

        return \is_string($agent) && '' !== $agent ? $agent : null;
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<mixed>
     */
    private function resetOptions(array $options, string $url, ?string $type): array
    {
        $options['headers'][SandBoxService::URL_HEADER] = $url;
        $options['headers'][SandBoxService::EVENT_HEADER] = $this->getEvent($type);

        if ('' !== $this->sandboxService->getApiKey()) {
            $options['headers'][SandBoxService::API_KEY_HEADER] = $this->sandboxService->getApiKey();
        }

        unset($options[self::PROXY_HEADER], $options['base_uri']);

        return $options;
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
