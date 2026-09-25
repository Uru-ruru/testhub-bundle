<?php

declare(strict_types=1);

namespace TestHub\Bundle\HttpClient;

use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps the outgoing requests seen by the decorator during the current main request.
 */
final class SandBoxRequestLog implements ResetInterface
{
    /**
     * @var list<array{request: array<string, mixed>, response: ResponseInterface}>
     */
    private array $entries = [];

    /**
     * @param array<string, mixed> $request
     */
    public function add(array $request, ResponseInterface $response): void
    {
        $this->entries[] = ['request' => $request, 'response' => $response];
    }

    /**
     * @return list<array{request: array<string, mixed>, response: ResponseInterface}>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
