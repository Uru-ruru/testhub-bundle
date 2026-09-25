<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Functional\App;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use TestHub\Bundle\Action\ActionInterface;

final class CallApiAction implements ActionInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function getName(): string
    {
        return 'call-api';
    }

    public function getLabel(): string
    {
        return 'Call API';
    }

    public function getDescription(): string
    {
        return 'Sends a withdrawal to the PSP.';
    }

    public function getParameters(): array
    {
        return ['amount' => 'Amount'];
    }

    public function run(array $parameters): string
    {
        if ('' === $parameters['amount']) {
            throw new \InvalidArgumentException('Amount is required.');
        }

        echo 'legacy output';

        $this->httpClient->request('POST', 'https://psp.example/withdraw', [
            'json' => ['amount' => $parameters['amount']],
            'extra' => ['sandboxRequestType' => 'withdraw'],
        ])->getContent();

        return 'Sent '.$parameters['amount'].'.';
    }
}
