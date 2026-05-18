<?php

declare(strict_types=1);
 
namespace TestHub\Bundle\HttpClientDecorator;
 
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use TestHub\Bundle\Service\SandBoxService;

final class SandBoxHttpClientDecorator implements HttpClientInterface
{
    use DecoratorTrait;
    private const string PROXY_HEADER = 'proxy';
    private const string DEPOSIT_TYPE = 'deposit';
    private const string WITHDRAWAL_TYPE = 'withdrawal';

    public function __construct(
        private HttpClientInterface $client,
        private readonly ContextProviderInterface $contextCollector,
        private readonly SandBoxService $sandboxService,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * @param array<mixed> $options
     *
     * @throws TransportExceptionInterface
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $this->setSandBoxRequestUrl($options), $this->resetOptions($options, $url));
    }

    private function setSandBoxRequestUrl(array $options): string
    {
        $context = $this->contextCollector->get();

        $type = $options['extra']['curl'][SandBoxService::SANDBOX_TYPE] ?? null;

        if ($type === null) {
            return $this->sandboxService->getUrlWrap();
        }

        return $this->sandboxService->getUrl() . '/' . $context[0]->getAgent() . '/' . $type . '/' . $this->getEvent($type);
    }

    private function resetOptions(array $options, $url): array
    {
        $options['headers']['url'] = $url;
        $options['headers']['event'] = $this->getEvent($options['extra']['curl'][SandBoxService::SANDBOX_TYPE] ?? null);

        unset($options[self::PROXY_HEADER], $options['extra']['curl'][SandBoxService::SANDBOX_TYPE]);

        return $options;
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

        if ($cookie === null) {
            return SandBoxDataCollector::EVENT_SUCCESS;
        }

        $event = $this->requestStack->getCurrentRequest()?->cookies->get($cookie);

        return \in_array($event, [SandBoxDataCollector::EVENT_SUCCESS, SandBoxDataCollector::EVENT_FAIL], true)
            ? $event
            : SandBoxDataCollector::EVENT_SUCCESS;
    }
}
